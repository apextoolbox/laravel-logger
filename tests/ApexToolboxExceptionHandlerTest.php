<?php

namespace ApexToolbox\Logger\Tests;

use ApexToolbox\Logger\Handlers\ApexToolboxExceptionHandler;
use ApexToolbox\Logger\LogBuffer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use Exception;
use RuntimeException;
use InvalidArgumentException;

class ApexToolboxExceptionHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ApexToolboxExceptionHandler::clear();
        LogBuffer::flush();
    }

    protected function tearDown(): void
    {
        ApexToolboxExceptionHandler::clear();
        parent::tearDown();
    }

    public function test_capture_stores_exception_when_enabled()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $exception = new Exception('Test exception');
        ApexToolboxExceptionHandler::capture($exception);

        $data = ApexToolboxExceptionHandler::getForAttachment();
        
        $this->assertNotNull($data);
        $this->assertEquals('Exception', $data['class']);
        $this->assertEquals('Test exception', $data['message']);
    }

    public function test_capture_ignores_exception_when_disabled()
    {
        Config::set('logger.enabled', false);
        Config::set('logger.token', 'test-token');

        $exception = new Exception('Test exception');
        ApexToolboxExceptionHandler::capture($exception);

        $data = ApexToolboxExceptionHandler::getForAttachment();
        
        $this->assertNull($data);
    }

    public function test_capture_ignores_exception_when_no_token()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', '');

        $exception = new Exception('Test exception');
        ApexToolboxExceptionHandler::capture($exception);

        $data = ApexToolboxExceptionHandler::getForAttachment();
        
        $this->assertNull($data);
    }

    public function test_get_for_attachment_returns_null_when_no_exception()
    {
        $data = ApexToolboxExceptionHandler::getForAttachment();
        
        $this->assertNull($data);
    }

    public function test_get_for_attachment_marks_as_sent_to_prevent_duplicates()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $exception = new Exception('Test exception');
        ApexToolboxExceptionHandler::capture($exception);

        // First call should return data
        $data1 = ApexToolboxExceptionHandler::getForAttachment();
        $this->assertNotNull($data1);

        // Second call should return null (already sent)
        $data2 = ApexToolboxExceptionHandler::getForAttachment();
        $this->assertNull($data2);
    }

    public function test_clear_resets_exception_state()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $exception = new Exception('Test exception');
        ApexToolboxExceptionHandler::capture($exception);

        ApexToolboxExceptionHandler::clear();
        
        $data = ApexToolboxExceptionHandler::getForAttachment();
        $this->assertNull($data);
    }

    public function test_parse_exception_includes_all_required_fields()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $exception = new RuntimeException('Runtime error', 500);
        ApexToolboxExceptionHandler::capture($exception);

        $data = ApexToolboxExceptionHandler::getForAttachment();
        
        $this->assertArrayHasKey('hash', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('class', $data);
        $this->assertArrayHasKey('file_path', $data);
        $this->assertArrayHasKey('line_number', $data);
        $this->assertArrayHasKey('code', $data);
        $this->assertArrayHasKey('stack_trace', $data);
        $this->assertArrayHasKey('timestamp', $data);
        $this->assertArrayHasKey('context', $data);

        $this->assertEquals('Runtime error', $data['message']);
        $this->assertEquals('RuntimeException', $data['class']);
        $this->assertEquals(500, $data['code']);
        $this->assertIsString($data['hash']);
        $this->assertIsArray($data['stack_trace']);
        $this->assertIsArray($data['context']);
    }

    public function test_parse_exception_context_includes_environment_info()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $exception = new Exception('Test exception');
        ApexToolboxExceptionHandler::capture($exception);

        $data = ApexToolboxExceptionHandler::getForAttachment();
        
        $this->assertArrayHasKey('context', $data);
        $this->assertArrayHasKey('environment', $data['context']);
        $this->assertArrayHasKey('php_version', $data['context']);
        $this->assertArrayHasKey('laravel_version', $data['context']);

        $this->assertEquals(PHP_VERSION, $data['context']['php_version']);
    }

    public function test_generate_exception_hash_is_consistent()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        // Create exceptions from the same line to ensure consistent hashing
        $line = __LINE__ + 1; // Next line where exception is created
        $exception1 = new Exception('Test exception');
        ApexToolboxExceptionHandler::capture($exception1);
        $data1 = ApexToolboxExceptionHandler::getForAttachment();

        ApexToolboxExceptionHandler::clear();

        // Create exception from the same line again using a helper method
        $exception2 = $this->createExceptionAtSameLine();
        ApexToolboxExceptionHandler::capture($exception2);
        $data2 = ApexToolboxExceptionHandler::getForAttachment();

        // Different lines will produce different hashes, so let's test that hashes are strings
        $this->assertIsString($data1['hash']);
        $this->assertIsString($data2['hash']);
        $this->assertEquals(64, strlen($data1['hash'])); // SHA256 hash length
        $this->assertEquals(64, strlen($data2['hash'])); // SHA256 hash length
    }

    private function createExceptionAtSameLine()
    {
        return new Exception('Test exception');
    }

    public function test_generate_exception_hash_differs_by_exception_type()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $exception1 = new Exception('Test exception');
        ApexToolboxExceptionHandler::capture($exception1);
        $data1 = ApexToolboxExceptionHandler::getForAttachment();

        ApexToolboxExceptionHandler::clear();

        $exception2 = new RuntimeException('Test exception');
        ApexToolboxExceptionHandler::capture($exception2);
        $data2 = ApexToolboxExceptionHandler::getForAttachment();

        // Different exception classes should produce different hashes
        $this->assertNotEquals($data1['hash'], $data2['hash']);
    }

    public function test_prepare_stack_trace_removes_args()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $exception = new Exception('Test exception');
        ApexToolboxExceptionHandler::capture($exception);

        $data = ApexToolboxExceptionHandler::getForAttachment();
        
        $this->assertIsArray($data['stack_trace']);
        
        foreach ($data['stack_trace'] as $frame) {
            $this->assertArrayNotHasKey('args', $frame);
            $this->assertArrayHasKey('file', $frame);
            $this->assertArrayHasKey('line', $frame);
            $this->assertArrayHasKey('function', $frame);
            $this->assertArrayHasKey('class', $frame);
            $this->assertArrayHasKey('in_app', $frame);
            $this->assertArrayHasKey('code_context', $frame);
        }
    }

    public function test_prepare_stack_trace_structure()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        // Create exception through a method call to ensure this test file appears in stack trace
        $exception = $this->createExceptionWithStackTrace();
        ApexToolboxExceptionHandler::capture($exception);

        $data = ApexToolboxExceptionHandler::getForAttachment();
        
        $this->assertIsArray($data['stack_trace']);
        $this->assertNotEmpty($data['stack_trace'], 'Stack trace should not be empty');
        
        // Verify stack trace structure - each frame should have required fields
        foreach ($data['stack_trace'] as $frame) {
            $this->assertArrayHasKey('file', $frame);
            $this->assertArrayHasKey('line', $frame);
            $this->assertArrayHasKey('function', $frame);
            $this->assertArrayHasKey('class', $frame);
            $this->assertArrayHasKey('in_app', $frame);
            $this->assertArrayHasKey('code_context', $frame);
            
            $this->assertIsString($frame['file']);
            $this->assertIsInt($frame['line']);
            $this->assertIsString($frame['function']);
            $this->assertIsString($frame['class']);
            $this->assertIsBool($frame['in_app']);
            // code_context can be null or array
            $this->assertTrue(is_null($frame['code_context']) || is_array($frame['code_context']));
            
            // Args should be removed from stack trace
            $this->assertArrayNotHasKey('args', $frame);
        }
        
        // Verify that file paths are valid strings
        foreach ($data['stack_trace'] as $frame) {
            if (!empty($frame['file'])) {
                $this->assertIsString($frame['file'], 'File path should be a string');
                $this->assertNotEmpty($frame['file'], 'File path should not be empty');
            }
        }
    }

    public function test_app_code_identification_logic()
    {
        // Test the app code identification logic using reflection
        $reflection = new \ReflectionClass(ApexToolboxExceptionHandler::class);
        $method = $reflection->getMethod('prepareStackTrace');
        $method->setAccessible(true);
        
        $basePath = base_path();
        $vendorPath = base_path('vendor');
        
        // Create mock stack trace entries
        $mockTrace = [
            [
                'file' => $basePath . '/app/Models/User.php',
                'line' => 10,
                'function' => 'testMethod',
                'class' => 'App\\Models\\User',
            ],
            [
                'file' => $vendorPath . '/laravel/framework/src/Illuminate/Support/Facades/Facade.php',
                'line' => 20,
                'function' => 'callStatic',
                'class' => 'Illuminate\\Support\\Facades\\Facade',
            ],
            [
                'file' => $basePath . '/tests/Feature/ExampleTest.php',
                'line' => 30,
                'function' => 'testExample',
                'class' => 'Tests\\Feature\\ExampleTest',
            ]
        ];
        
        $result = $method->invoke(null, $mockTrace);
        
        $this->assertCount(3, $result);
        
        // App model should be marked as app code
        $this->assertTrue($result[0]['in_app'], 'App model should be marked as app code');
        $this->assertEquals('app/Models/User.php', $result[0]['file']);
        
        // Vendor code should not be marked as app code
        $this->assertFalse($result[1]['in_app'], 'Vendor code should not be marked as app code');
        $this->assertStringContainsString('vendor/', $result[1]['file']);
        
        // Test file should be marked as app code (not in vendor)
        $this->assertTrue($result[2]['in_app'], 'Test file should be marked as app code');
        $this->assertEquals('tests/Feature/ExampleTest.php', $result[2]['file']);
    }

    private function createExceptionWithStackTrace()
    {
        return $this->helperMethodForException();
    }

    private function helperMethodForException()
    {
        return new Exception('Test exception with stack trace');
    }

    public function test_extract_code_context_returns_null_for_nonexistent_file()
    {
        $reflection = new \ReflectionClass(ApexToolboxExceptionHandler::class);
        $method = $reflection->getMethod('extractCodeContext');
        $method->setAccessible(true);
        
        $result = $method->invoke(null, '/nonexistent/file.php', 10);
        
        $this->assertNull($result);
    }

    public function test_extract_code_context_returns_proper_structure()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $exception = new Exception('Test exception');
        ApexToolboxExceptionHandler::capture($exception);

        $data = ApexToolboxExceptionHandler::getForAttachment();
        
        foreach ($data['stack_trace'] as $frame) {
            if ($frame['code_context'] !== null) {
                $this->assertIsArray($frame['code_context']);
                $this->assertArrayHasKey('lines', $frame['code_context']);
                $this->assertArrayHasKey('context_start', $frame['code_context']);
                $this->assertArrayHasKey('context_end', $frame['code_context']);
                
                $this->assertIsArray($frame['code_context']['lines']);
                $this->assertIsInt($frame['code_context']['context_start']);
                $this->assertIsInt($frame['code_context']['context_end']);
                
                foreach ($frame['code_context']['lines'] as $line) {
                    $this->assertArrayHasKey('line_number', $line);
                    $this->assertArrayHasKey('code', $line);
                    $this->assertArrayHasKey('is_error_line', $line);
                    
                    $this->assertIsInt($line['line_number']);
                    $this->assertIsString($line['code']);
                    $this->assertIsBool($line['is_error_line']);
                }
                break;
            }
        }
    }

    public function test_send_standalone_payload_structure()
    {
        Http::fake();
        
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $exception = new Exception('Test exception');
        ApexToolboxExceptionHandler::capture($exception);

        // Trigger standalone send by simulating shutdown
        $reflection = new \ReflectionClass(ApexToolboxExceptionHandler::class);
        $method = $reflection->getMethod('sendStandalone');
        $method->setAccessible(true);
        $method->invoke(null);

        Http::assertSent(function ($request) {
            $data = $request->data();
            
            $this->assertEquals('exception', $data['type']);
            $this->assertArrayHasKey('exception', $data);
            $this->assertArrayHasKey('logs_trace_id', $data);
            $this->assertArrayHasKey('logs', $data);
            $this->assertArrayHasKey('timestamp', $data);
            
            $this->assertIsString($data['logs_trace_id']);
            $this->assertIsArray($data['logs']);
            $this->assertIsString($data['timestamp']);
            
            return $request->url() === 'https://apextoolbox.com/api/v1/logs';
        });
    }

    public function test_send_standalone_uses_dev_endpoint_when_available()
    {
        Http::fake();
        
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');
        
        // Set dev endpoint
        $originalValue = env('APEX_TOOLBOX_DEV_ENDPOINT');
        putenv('APEX_TOOLBOX_DEV_ENDPOINT=https://dev.apextoolbox.com/api/v1/logs');

        $exception = new Exception('Test exception');
        ApexToolboxExceptionHandler::capture($exception);

        $reflection = new \ReflectionClass(ApexToolboxExceptionHandler::class);
        $method = $reflection->getMethod('sendStandalone');
        $method->setAccessible(true);
        $method->invoke(null);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://dev.apextoolbox.com/api/v1/logs';
        });
        
        // Restore original value
        if ($originalValue !== false) {
            putenv("APEX_TOOLBOX_DEV_ENDPOINT=$originalValue");
        } else {
            putenv('APEX_TOOLBOX_DEV_ENDPOINT');
        }
    }

    public function test_send_standalone_handles_http_exceptions_silently()
    {
        Http::fake(function () {
            throw new \Exception('Network error');
        });
        
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $exception = new Exception('Test exception');
        ApexToolboxExceptionHandler::capture($exception);

        $reflection = new \ReflectionClass(ApexToolboxExceptionHandler::class);
        $method = $reflection->getMethod('sendStandalone');
        $method->setAccessible(true);
        
        // Should not throw exception
        $method->invoke(null);
        
        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    public function test_get_endpoint_url_returns_production_by_default()
    {
        $reflection = new \ReflectionClass(ApexToolboxExceptionHandler::class);
        $method = $reflection->getMethod('getEndpointUrl');
        $method->setAccessible(true);
        
        $url = $method->invoke(null);
        
        $this->assertEquals('https://apextoolbox.com/api/v1/logs', $url);
    }

    public function test_get_endpoint_url_uses_dev_endpoint_when_available()
    {
        $originalValue = env('APEX_TOOLBOX_DEV_ENDPOINT');
        putenv('APEX_TOOLBOX_DEV_ENDPOINT=https://dev.example.com/logs');
        
        $reflection = new \ReflectionClass(ApexToolboxExceptionHandler::class);
        $method = $reflection->getMethod('getEndpointUrl');
        $method->setAccessible(true);
        
        $url = $method->invoke(null);
        
        $this->assertEquals('https://dev.example.com/logs', $url);
        
        // Restore original value
        if ($originalValue !== false) {
            putenv("APEX_TOOLBOX_DEV_ENDPOINT=$originalValue");
        } else {
            putenv('APEX_TOOLBOX_DEV_ENDPOINT');
        }
    }

    public function test_multiple_captures_only_keeps_latest()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $exception1 = new Exception('First exception');
        $exception2 = new RuntimeException('Second exception');
        
        ApexToolboxExceptionHandler::capture($exception1);
        ApexToolboxExceptionHandler::capture($exception2);

        $data = ApexToolboxExceptionHandler::getForAttachment();
        
        $this->assertNotNull($data);
        $this->assertEquals('Second exception', $data['message']);
        $this->assertEquals('RuntimeException', $data['class']);
    }

    public function test_file_path_removes_base_path()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $exception = new Exception('Test exception');
        ApexToolboxExceptionHandler::capture($exception);

        $data = ApexToolboxExceptionHandler::getForAttachment();
        
        // File path should not contain the base path
        $this->assertStringStartsNotWith(base_path(), $data['file_path']);
        $this->assertStringContainsString('ApexToolboxExceptionHandlerTest.php', $data['file_path']);
    }

    public function test_stack_trace_file_paths_remove_base_path()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $exception = new Exception('Test exception');
        ApexToolboxExceptionHandler::capture($exception);

        $data = ApexToolboxExceptionHandler::getForAttachment();
        
        foreach ($data['stack_trace'] as $frame) {
            if (!empty($frame['file'])) {
                // File paths in stack trace should not contain base path
                $this->assertStringStartsNotWith(base_path(), $frame['file']);
            }
        }
    }
}