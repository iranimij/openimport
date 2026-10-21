#!/usr/bin/env bash
# Integration tests against a real search engine. The ExtDN action
# (which wires only Elasticsearch 8) is not used: this script builds the Magento project from the public mirror,
# adds this checkout as a path package, points the test framework at OpenSearch (or Elasticsearch 8 on 2.4.7)
# and runs Test/Integration (OpenImport drives core import, which reindexes into the engine). MySQL and the search engine are started by the workflow.
#
# Env: MAGENTO_VERSION (e.g. 2.4.9), SEARCH_ENGINE (opensearch | elasticsearch8), MODULE_DIR (this checkout).
set -euo pipefail

: "${MAGENTO_VERSION:?}" "${SEARCH_ENGINE:=opensearch}" "${MODULE_DIR:?}" "${MAGENTO_ROOT:=/tmp/magento}"
MIRROR=https://repo-magento-mirror.fooman.co.nz/
package="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["name"];' "$MODULE_DIR/composer.json")"

echo "::group::Composer project $MAGENTO_VERSION"
if [ -n "${GITHUB_TOKEN:-}" ]; then composer config -g github-oauth.github.com "$GITHUB_TOKEN"; fi
composer create-project --no-interaction --no-progress --no-install --repository-url="$MIRROR" \
  "magento/project-community-edition=$MAGENTO_VERSION" "$MAGENTO_ROOT"
cd "$MAGENTO_ROOT"
php -r '$f = "composer.json"; $c = json_decode(file_get_contents($f), true);
  $c["repositories"] = ["magento-mirror" => ["type" => "composer", "url" => $argv[1]]];
  file_put_contents($f, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");' "$MIRROR"
composer config repositories.module '{"type":"path","url":"'"$MODULE_DIR"'","options":{"symlink":false,"versions":{"'"$package"'":"1.0.0"}}}'
# Extra path packages: space-separated "name=dir" pairs.
for extra in ${EXTRA_PATH_PACKAGES:-}; do
  name="${extra%%=*}"; dir="${extra#*=}"
  composer config "repositories.${name//\//-}" '{"type":"path","url":"'"$dir"'","options":{"symlink":false,"versions":{"'"$name"'":"1.0.0"}}}'
done
# Composer 2.9+ refuses packages with security advisories while resolving; old Magento patch releases pin some of
# them (league/flysystem 2.x on 2.4.7). CI only builds a throwaway test project, so resolve as Magento declares.
php -r '$f = "composer.json"; $c = json_decode(file_get_contents($f), true);
  $c["config"]["policy"]["advisories"]["block"] = false; $c["config"]["audit"]["block-insecure"] = false;
  file_put_contents($f, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");'
composer config --no-plugins allow-plugins.magento/composer-dependency-version-audit-plugin false
composer config --no-plugins allow-plugins.magento/* true
composer config --no-plugins allow-plugins.laminas/laminas-dependency-plugin true
composer config --no-plugins allow-plugins.dealerdirect/phpcodesniffer-composer-installer true
composer config --no-plugins allow-plugins.php-http/discovery true
composer require --no-update --no-interaction "$package:1.0.0"
"$MODULE_DIR/.github/ci/logged.sh" "composer install" composer install --no-interaction --no-progress --prefer-dist \
  || { echo "composer install failed, retrying once"; composer clear-cache; "$MODULE_DIR/.github/ci/logged.sh" "composer install (retry)" composer install --no-interaction --no-progress --prefer-dist; }
echo "::endgroup::"

echo "::group::Test framework configuration ($SEARCH_ENGINE)"
cd dev/tests/integration
# The .dist file reads constants of Magento\TestFramework\Bootstrap (not autoloadable here), so the configuration is
# written directly; the admin values are the test framework's own defaults (Bootstrap::ADMIN_*).
php -r '
  $engine = $argv[1];
  $prefix = $engine === "opensearch" ? "opensearch" : "elasticsearch";
  $c = [
      "db-host" => "127.0.0.1", "db-user" => "root", "db-password" => "root", "db-name" => "magento_integration_tests",
      "db-prefix" => "", "backend-frontname" => "backend", "admin-user" => "user", "admin-password" => "password1",
      "admin-email" => "admin@example.com", "admin-firstname" => "firstname", "admin-lastname" => "lastname",
      "search-engine" => $engine, $prefix . "-host" => "127.0.0.1", $prefix . "-port" => 9200,
      "consumers-wait-for-messages" => "0",
  ];
  file_put_contents("etc/install-config-mysql.php", "<?php\nreturn " . var_export($c, true) . ";\n");
' "$SEARCH_ENGINE"
cat etc/install-config-mysql.php
cp phpunit.xml.dist phpunit.xml
echo "::endgroup::"

echo "::group::Unit tests (Magento's unit test framework)"
# From dev/tests/unit like core's own runs: its PHPUnit extensions read their config relative to that folder.
( cd "$MAGENTO_ROOT/dev/tests/unit" && "$MODULE_DIR/.github/ci/logged.sh" "Unit $MAGENTO_VERSION" \
    php -d memory_limit=-1 ../../../vendor/bin/phpunit -c phpunit.xml.dist "../../../vendor/$package/Test/Unit" )
echo "::endgroup::"

echo "::group::Integration tests"
# The TeamCity log is written while the tests run, so a crash before PHPUnit's summary still names the failed tests.
export OI_TEAMCITY_LOG="${RUNNER_TEMP:-/tmp}/integration-teamcity.log"
"$MODULE_DIR/.github/ci/logged.sh" "Integration $MAGENTO_VERSION" \
  php -d memory_limit=-1 ../../../vendor/bin/phpunit -c phpunit.xml --log-teamcity "$OI_TEAMCITY_LOG" ${PHPUNIT_ARGS:-} \
  "../../../vendor/$package/Test/Integration"
echo "::endgroup::"
