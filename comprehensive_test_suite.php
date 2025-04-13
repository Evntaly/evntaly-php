<?php
/**
 * Run comprehensive tests for all Evntaly SDK features
 */

echo "=====================================================\n";
echo "EVNTALY SDK COMPREHENSIVE TEST SUITE\n";
echo "=====================================================\n\n";

// Original tests, if they exist
if (file_exists('run_all_tests.php')) {
    echo "ORIGINAL TESTS\n";
    echo "-----------------------------------------------------\n";
    include('run_all_tests.php');
    echo "\n\n";
}

// Error handling tests
echo "ERROR HANDLING TESTS\n";
echo "-----------------------------------------------------\n";
include('test_error_handling.php');
echo "\n\n";

// Batch processing tests
echo "BATCH PROCESSING TESTS\n";
echo "-----------------------------------------------------\n";
include('test_batch_processing.php');
echo "\n\n";

// Encryption tests
echo "FIELD-LEVEL ENCRYPTION TESTS\n";
echo "-----------------------------------------------------\n";
include('test_encryption.php');
echo "\n\n";

// Asynchronous operations tests
echo "ASYNCHRONOUS OPERATIONS TESTS\n";
echo "-----------------------------------------------------\n";
include('test_async.php');
echo "\n\n";

// OpenTelemetry tests
echo "OPENTELEMETRY INTEGRATION TESTS\n";
echo "-----------------------------------------------------\n";
include('test_opentelemetry.php');

echo "\n=====================================================\n";
echo "ALL TESTS COMPLETED\n";
echo "=====================================================\n";