<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Google;

use Iranimij\OpenImport\Model\Google\GoogleAuth;
use Iranimij\OpenImport\Model\Google\GoogleEndpoints;
use Iranimij\OpenImport\Model\Http\ApiClient;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use PHPUnit\Framework\TestCase;

class GoogleAuthTest extends TestCase
{
    private function auth(): GoogleAuth
    {
        // No request is sent in these tests.
        $api = (new \ReflectionClass(ApiClient::class))->newInstanceWithoutConstructor();

        return new GoogleAuth($api, new GoogleEndpoints('https://auth.example/authorize'));
    }

    public function testPkceChallengeOfRfc7636(): void
    {
        self::assertSame(
            'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            $this->auth()->challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk')
        );
    }

    public function testTheConsentPageAsksForOfflineAccessWithStateAndChallenge(): void
    {
        $url = $this->auth()->consentUrl('client-1', 'https://shop.example/admin/openimport/connection/oauthCallback/', 'st4te', 'verifier');
        self::assertStringStartsWith('https://auth.example/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('code', $query['response_type']);
        self::assertSame('client-1', $query['client_id']);
        self::assertSame('https://shop.example/admin/openimport/connection/oauthCallback/', $query['redirect_uri']);
        self::assertSame(GoogleAuth::SCOPE, $query['scope']);
        self::assertSame('offline', $query['access_type']);
        self::assertSame('consent', $query['prompt']);
        self::assertSame('st4te', $query['state']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame($this->auth()->challenge('verifier'), $query['code_challenge']);
    }

    public function testAServiceAccountKeyIsChecked(): void
    {
        self::assertSame(
            'importer@project.iam.gserviceaccount.com',
            $this->auth()->serviceAccountEmail((string) json_encode([
                'type' => 'service_account',
                'client_email' => 'importer@project.iam.gserviceaccount.com',
                'private_key' => '-----BEGIN PRIVATE KEY-----',
            ]))
        );
        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage('Paste the JSON key of a Google service account');
        $this->auth()->serviceAccountEmail('{"type": "authorized_user"}');
    }

    public function testASignedInConnectionNeedsItsRefreshToken(): void
    {
        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage('The Google connection is not signed in yet');
        $this->auth()->token(['auth' => GoogleAuth::OAUTH, 'client_id' => 'c', 'client_secret' => 's']);
    }
}
