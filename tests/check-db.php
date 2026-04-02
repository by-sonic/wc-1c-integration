<?php
$conn = @new mysqli('db', 'wp', 'wp', 'wordpress', 3306);
if ($conn->connect_error) {
    echo "DB_FAIL: " . $conn->connect_error;
    exit(1);
}
echo "DB_OK";
$conn->close();
