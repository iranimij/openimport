#!/usr/bin/env python3
"""Every phrase the module shows must have a line in i18n/en_US.csv and i18n/de_DE.csv.

Collects __('...') and __("...") in PHP and templates, $t('...') in JavaScript, translate="'...'" and
i18n: '...' in Knockout templates, and translate="true" texts in layout, UI component and system XML.
Exit code 1 lists the missing phrases.
"""
import csv
import glob
import os
import re
import sys
import xml.etree.ElementTree as ET

ROOT = os.path.abspath(sys.argv[1] if len(sys.argv) > 1 else os.path.join(os.path.dirname(__file__), '..', '..'))
SKIP = ('/Test/', '/vendor/', '/.github/', '/docs/', '/node_modules/')


def files(pattern):
    for path in glob.glob(os.path.join(ROOT, '**', pattern), recursive=True):
        if not any(part in path.replace(os.sep, '/') for part in SKIP):
            yield path


def php_string(body, quote):
    if quote == "'":
        return body.replace("\\'", "'").replace('\\\\', '\\')
    return body.encode('utf-8').decode('unicode_escape').encode('latin-1').decode('utf-8')


phrases = {}
problems = []
joined = re.compile(r"""(?<![\w>$])(?:__|\$t)\(\s*(['"])((?:\\.|(?!\1).)*)\1\s*\.""", re.S)
call = re.compile(r"""(?<![\w>$])(?:__|\$t)\(\s*(['"])((?:\\.|(?!\1).)*)\1\s*[,)]""", re.S)
for pattern in ('*.php', '*.phtml', '*.js'):
    for path in files(pattern):
        text = open(path, encoding='utf-8').read()
        for match in call.finditer(text):
            phrases.setdefault(php_string(match.group(2), match.group(1)), path)
        for match in joined.finditer(text):
            problems.append(f'{os.path.relpath(path, ROOT)}: a phrase built with "." cannot be translated: '
                            f'{match.group(2)[:60]}...')

ko = re.compile(r"""(?:translate="'((?:[^'\\]|\\.)*)'"|i18n:\s*'((?:[^'\\]|\\.)*)'|\$t\('((?:[^'\\]|\\.)*)'\))""")
for path in files('*.html'):
    for match in ko.finditer(open(path, encoding='utf-8').read()):
        # In Knockout attribute bindings a colon is written as "\:".
        phrase = (match.group(1) or match.group(2) or match.group(3)).replace("\\'", "'").replace('\\:', ':')
        phrases.setdefault(phrase, path)

for path in files('*.xml'):
    try:
        tree = ET.parse(path)
    except ET.ParseError as error:
        print(f'{path}: {error}')
        sys.exit(1)
    for node in tree.iter():
        if node.get('translate') == 'true' and (node.text or '').strip():
            phrases.setdefault(node.text.strip(), path)
        for attribute in ('translate',):
            names = node.get(attribute)
            if names and names != 'true' and names != 'false':
                for name in names.split():
                    child = node.find(name)
                    if child is not None and (child.text or '').strip():
                        phrases.setdefault(child.text.strip(), path)
                    elif node.get(name):
                        phrases.setdefault(node.get(name), path)

missing = 0
for language in ('en_US', 'de_DE'):
    known = {row[0] for row in csv.reader(open(os.path.join(ROOT, 'i18n', language + '.csv'), encoding='utf-8')) if row}
    for phrase, path in sorted(phrases.items()):
        if phrase not in known:
            missing += 1
            print(f'{language}: missing "{phrase}" ({os.path.relpath(path, ROOT)})')

for problem in problems:
    print(problem)
print(f'{len(phrases)} phrases, {missing} missing')
sys.exit(1 if missing or problems else 0)
