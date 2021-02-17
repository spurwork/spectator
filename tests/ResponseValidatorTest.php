<?php

namespace Spectator\Tests;

use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Spectator\Middleware;
use Spectator\Spectator;
use Spectator\SpectatorServiceProvider;
use Spectator\Tests\Models\TestUser;

class ResponseValidatorTest extends TestCase
{
    const NULLABLE_MISSING = 0;
    const NULLABLE_EMPTY = 1;
    const NULLABLE_VALID = 2;
    const NULLABLE_INVALID = 3;
    const NULLABLE_NULL = 4;

    public function setUp(): void
    {
        parent::setUp();

        $this->app->register(SpectatorServiceProvider::class);

        Spectator::using('Test.v1.json');
    }

    public function test_validates_valid_json_response()
    {
        Route::get('/users', function () {
            return [
                [
                    'id' => 1,
                    'name' => 'Jim',
                    'email' => 'test@test.test',
                ],
            ];
        })->middleware(Middleware::class);

        $this->getJson('/users')
            ->assertValidRequest()
            ->assertValidResponse(200);
    }

    public function test_validates_invalid_json_response()
    {
        Route::get('/users', function () {
            return [
                [
                    'id' => 'invalid',
                ],
            ];
        })->middleware(Middleware::class);

        $this->getJson('/users')
            ->assertValidRequest()
            ->assertInvalidResponse(400)
            ->assertValidationMessage('get-users json response field 0.id does not match the spec: [ type: {"expected":"number","used":"string"} ]');

        Route::get('/users', function () {
            return [
                [
                    'id' => 1,
                    'email' => 'invalid',
                ],
            ];
        })->middleware(Middleware::class);

        $this->getJson('/users')
            ->assertValidRequest()
            ->assertInvalidResponse(400)
            ->assertValidationMessage('get-users json response field 0.email does not match the spec: [ format: {"type":"string","format":"email"} ]');
    }

    public function test_fallback_to_request_uri_if_operationId_not_given()
    {
        Spectator::using('Test.v1.json');

        Route::get('/path-without-operationId', function () {
            return [
                'int' => 'not an int',
            ];
        })->middleware(Middleware::class);

        $this->getJson('/path-without-operationId')
            ->assertValidRequest()
            ->assertInvalidResponse(400)
            ->assertValidationMessage('path-without-operationId json response field int does not match the spec: [ type: {"expected":"integer","used":"string"} ]');
    }

    public function test_cannot_locate_path_without_path_prefix()
    {
        Spectator::using('Test.v2.json');

        Route::get('/api/v2/users', function () {
            return [
                [
                    'id' => 1,
                    'name' => 'Jim',
                    'email' => 'test@test.test',
                ],
            ];
        })->middleware(Middleware::class);

        $this->getJson('/api/v2/users')
            ->assertInvalidRequest()
            ->assertValidationMessage('Path [GET /api/v2/users] not found in spec.');

        Config::set('spectator.path_prefix', '/api/v2/');

        $this->getJson('/api/v2/users')
            ->assertValidRequest()
            ->assertValidResponse(200);
    }

    public function test_uncaught_exceptions_are_thrown_when_exception_handling_is_disabled(): void
    {
        Route::get('/users', function () {
            throw new Exception('Something went wrong in the codebase!');
        })->middleware(Middleware::class);

        try {
            $this->withoutExceptionHandling()->getJson('/users');
        } catch (Exception $e) {
            $this->assertEquals('Something went wrong in the codebase!', $e->getMessage());

            return;
        }

        $this->fail('Failed asserting an exception was thrown');
    }

    /**
     * @dataProvider nullableProvider
     */
    public function test_handle_nullables(
        $version,
        $state,
        $is_valid
    ) {
        Spectator::using("Nullable.{$version}.json");

        Route::get('/users/{user}', function () use ($state) {
            $return = [
                'first_name' => 'Joe',
                'last_name' => 'Bloggs',
                'posts' => [
                    ['title' => 'My first post!'],
                ],
            ];

            if ($state === self::NULLABLE_EMPTY) {
                $return['email'] = '';
                $return['settings']['last_updated_at'] = '';
                $return['settings']['notifications']['email'] = '';
                $return['posts'][0]['body'] = '';
            }

            if ($state === self::NULLABLE_VALID) {
                $return['email'] = 'test@test.com';
            }

            if ($state === self::NULLABLE_INVALID) {
                $return['email'] = [1, 2, 3];
                $return['settings']['last_updated_at'] = [1, 2, 3];
                $return['settings']['notifications']['email'] = [1, 2, 3];
                $return['posts'][0]['body'] = [1, 2, 3];
            }

            if ($state === self::NULLABLE_NULL) {
                $return['email'] = null;
                $return['settings']['last_updated_at'] = null;
                $return['settings']['notifications']['email'] = null;
                $return['posts'][0]['body'] = null;
            }

            return $return;
        })->middleware(Middleware::class);

        if ($is_valid) {
            $this->getJson('/users/1')
                ->assertValidRequest()
                ->assertValidResponse();
        } else {
            $this->getJson('/users/1')
                ->assertValidRequest()
                ->assertInvalidResponse();
        }
    }

    public function nullableProvider()
    {
        $validResponse = true;
        $invalidResponse = false;

        $v30 = '3.0';
        $v31 = '3.1';

        return [
            // OA 3.0.0
            '3.0, missing' => [
                $v30,
                self::NULLABLE_MISSING,
                $validResponse,
            ],

            '3.0, empty' => [
                $v30,
                self::NULLABLE_EMPTY,
                $validResponse,
            ],

            '3.0, null' => [
                $v30,
                self::NULLABLE_NULL,
                $validResponse,
            ],

            '3.0, valid' => [
                $v30,
                self::NULLABLE_VALID,
                $validResponse,
            ],

            '3.0, invalid' => [
                $v30,
                self::NULLABLE_INVALID,
                $invalidResponse,
            ],

            // OA 3.1.0
            '3.1, missing' => [
                $v31,
                self::NULLABLE_MISSING,
                $validResponse,
            ],

            '3.1, empty' => [
                $v31,
                self::NULLABLE_EMPTY,
                $validResponse,
            ],

            '3.1, null' => [
                $v31,
                self::NULLABLE_NULL,
                $validResponse,
            ],

            '3.1, valid' => [
                $v31,
                self::NULLABLE_VALID,
                $validResponse,
            ],

            '3.1, invalid' => [
                $v31,
                self::NULLABLE_INVALID,
                $invalidResponse,
            ],
        ];
    }

    public function test_handles_invalid_spec()
    {
        Spectator::using('Malformed.v1.yaml');

        Route::get('/')->middleware(Middleware::class);

        $this->getJson('/')
            ->assertInvalidRequest()
            ->assertInvalidResponse()
            ->assertValidationMessage('The spec file is invalid. Please lint it using spectral (https://github.com/stoplightio/spectral) before trying again.');
    }

    public function test_request_with_sanctum()
    {
        Sanctum::actingAs(
            new TestUser([
                'id' => 1,
                'name' => 'Jim',
                'email' => 'test@test.test',
            ]),
            ['*']
        );

        Route::get('/users', function () {
            return [
                Auth::user()->only(['id', 'name', 'email']),
            ];
        })->middleware([Middleware::class, 'auth:sanctum']);

        $this->getJson('/users')
            ->assertValidRequest()
            ->assertValidResponse(200);
    }
}
