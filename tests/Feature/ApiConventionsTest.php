<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Platform\Http\RequestId;
use Tests\TestCase;

/**
 * Ces tests verrouillent les conventions transverses : toute API de la
 * plateforme doit s'y conformer.
 *
 * @see docs/02-standards/api-guidelines.md
 */
final class ApiConventionsTest extends TestCase
{
    public function test_successful_responses_carry_the_standard_envelope(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()
            ->assertJsonStructure(['success', 'data', 'meta' => ['request_id']]);

        $this->assertTrue($response->json('success'));
    }

    public function test_unknown_endpoints_return_the_standard_error_envelope(): void
    {
        $this->getJson('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'ENDPOINT_NOT_FOUND')
            ->assertJsonStructure(['success', 'error' => ['code', 'message'], 'meta' => ['request_id']]);
    }

    public function test_request_id_is_returned_in_the_body_and_the_header(): void
    {
        $response = $this->getJson('/api/v1/health');

        $fromBody = $response->json('meta.request_id');

        $this->assertNotEmpty($fromBody);
        $this->assertSame($fromBody, $response->headers->get(RequestId::HEADER));
    }

    public function test_a_client_supplied_request_id_is_propagated(): void
    {
        $this->getJson('/api/v1/health', [RequestId::HEADER => 'req_from_client'])
            ->assertOk()
            ->assertJsonPath('meta.request_id', 'req_from_client');
    }

    public function test_a_malformed_client_request_id_is_replaced(): void
    {
        $response = $this->getJson('/api/v1/health', [RequestId::HEADER => 'not valid; drop table']);

        $response->assertOk();
        $this->assertNotSame('not valid; drop table', $response->json('meta.request_id'));
    }
}
