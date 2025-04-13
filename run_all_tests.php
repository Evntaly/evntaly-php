<?php
/**
 * Run all the tests for the features we fixed
 */

echo "======================================================\n";
echo "RUNNING ALL TESTS FOR EVNTALY SDK FIXES\n";
echo "======================================================\n\n";

echo "TEST 1: Fixed getMarkedEvents method\n";
echo "------------------------------------------------------\n";
include('test_marked_events.php');

echo "\n\n";
echo "TEST 2: Fixed HTTP client in checkLimit\n";
echo "------------------------------------------------------\n";
include('test_http_client.php');

echo "\n\n";
echo "TEST 3: DataSender implementation\n";
echo "------------------------------------------------------\n";
include('test_data_sender.php');

echo "\n======================================================\n";
echo "ALL TESTS COMPLETED\n";
echo "======================================================\n"; 