<?php
/**
 * Direct test of auth_api.php functionality
 */

// Simulate a POST request for login
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_CONTENT_TYPE'] = 'application/json';

// Create test input
$testData = [
    'email' => 'admin@demo.com',
    'password' => 'Admin123!'
];

// Simulate php://input
$input = json_encode($testData);

// Capture output
ob_start();

// Create a mock for file_get_contents('php://input')
stream_wrapper_unregister("php");
stream_wrapper_register("php", "MockPhpStream");

class MockPhpStream {
    private $position = 0;
    private static $data = '';

    public static function setData($data) {
        self::$data = $data;
    }

    public function stream_open($path, $mode, $options, &$opened_path) {
        return true;
    }

    public function stream_read($count) {
        $ret = substr(self::$data, $this->position, $count);
        $this->position += strlen($ret);
        return $ret;
    }

    public function stream_eof() {
        return $this->position >= strlen(self::$data);
    }

    public function stream_stat() {
        return [];
    }

    public function stream_seek($offset, $whence) {
        switch ($whence) {
            case SEEK_SET:
                $this->position = $offset;
                break;
            case SEEK_CUR:
                $this->position += $offset;
                break;
            case SEEK_END:
                $this->position = strlen(self::$data) + $offset;
                break;
        }
        return true;
    }

    public function stream_tell() {
        return $this->position;
    }
}

MockPhpStream::setData($input);

// Include the auth API
try {
    require_once 'auth_api.php';
} catch (Exception $e) {
    echo "Exception caught: " . $e->getMessage() . "\n";
}

$output = ob_get_clean();

// Restore normal php stream wrapper
stream_wrapper_restore("php");

// Check the output
echo "Output from auth_api.php:\n";
echo "------------------------\n";
echo $output;
echo "\n------------------------\n";

// Try to decode as JSON
$json = json_decode($output, true);
if (json_last_error() === JSON_ERROR_NONE) {
    echo "\n✓ Valid JSON response\n";
    echo "Response structure:\n";
    print_r($json);
} else {
    echo "\n✗ Invalid JSON response\n";
    echo "JSON Error: " . json_last_error_msg() . "\n";
    echo "First 500 characters of output:\n";
    echo substr($output, 0, 500) . "\n";
}
?>