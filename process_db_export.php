<?php

header('Content-Type: application/json');
date_default_timezone_set('Asia/Manila'); // Set the timezone to avoid warnings
ini_set('memory_limit', '2G'); // Adjust memory limit as needed
ini_set('max_execution_time', '300'); // Set to 300 seconds or adjust as needed

$servername = isset($_POST['servername']) ? $_POST['servername'] : '';
$username = isset($_POST['username']) ? $_POST['username'] : '';
$password = isset($_POST['password']) ? $_POST['password'] : '';
$dbname = isset($_POST['dbname']) ? $_POST['dbname'] : '';
$log = [];

$conn = new mysqli($servername, $username, $password); // Create connection

if ($conn->connect_error) { // Check connection
    $log[] = 'Connection failed: ' . $conn->connect_error;
    echo json_encode(['status' => 'error', 'message' => 'Connection failed: ' . $conn->connect_error, 'log' => $log]);
    exit;
}

$conn->set_charset("utf8"); // Set character set to UTF-8

$log[] = 'Connected to the server successfully';

// Get a list of databases if not exporting
if (!isset($_POST['export'])) {
    $result = $conn->query('SHOW DATABASES');

    if (!$result) {
        $log[] = 'Error getting database list: ' . $conn->error;
        echo json_encode(['status' => 'error', 'message' => 'Error getting database list: ' . $conn->error, 'log' => $log]);
        exit;
    }

    $databases = [];
    while ($row = $result->fetch_assoc()) {
        $databases[] = $row['Database'];
    }

    echo json_encode(['status' => 'success', 'message' => 'Connected to the server successfully.', 'databases' => $databases, 'log' => $log]);
    exit;
}

$exportOption = isset($_POST['exportOption']) ? $_POST['exportOption'] : ''; // Check export option

if (isset($_POST['export']) && isset($_POST['dbname']) && $exportOption !== '') {
    $dbname = $_POST['dbname'];

    $conn->select_db($dbname); // Select the database

    if ($exportOption === 'single') {
        exportAsSingle($conn, $dbname, $servername); // Export as single SQL file
    } elseif ($exportOption === 'zip') {
        exportAsZip($conn, $dbname, $servername); // Export as tables zip file
    }
}

function exportAsSingle($conn, $dbname, $servername)
{
    header('Content-Type: application/sql'); // Set headers for file download
    header('Content-Disposition: attachment; filename="' . $dbname . '.sql"');
    ob_start('ob_gzhandler'); // Open output buffer for compression
    echo "-- Database: `$dbname`\n\n"; // Initialize the SQL dump string
    flush();

    $tablesResult = $conn->query('SHOW TABLES'); // Fetch all tables in the database
    if (!$tablesResult) {
        $log[] = 'Error fetching tables: ' . $conn->error;
        echo json_encode(['status' => 'error', 'message' => 'Error fetching tables: ' . $conn->error, 'log' => $log]);
        exit;
    }

    // Loop through each table and export its structure and data
    while ($tableRow = $tablesResult->fetch_row()) {
        $table = $tableRow[0];

        $createTableResult = $conn->query("SHOW CREATE TABLE `$table`"); // Get the table creation query
        if (!$createTableResult) {
            $log[] = 'Error fetching table creation query: ' . $conn->error;
            echo json_encode(['status' => 'error', 'message' => 'Error fetching table creation query: ' . $conn->error, 'log' => $log]);
            exit;
        }
        $createTableRow = $createTableResult->fetch_row();
        echo $createTableRow[1] . ";\n\n";
        flush();

        $tableDataResult = $conn->query("SELECT * FROM `$table`"); // Fetch the table data
        if (!$tableDataResult) {
            $log[] = 'Error fetching table data: ' . $conn->error;
            echo json_encode(['status' => 'error', 'message' => 'Error fetching table data: ' . $conn->error, 'log' => $log]);
            exit;
        }

        $columnInfoResult = $conn->query("SHOW COLUMNS FROM `$table`"); // Get column information
        $columnInfo = [];
        while ($column = $columnInfoResult->fetch_assoc()) {
            $columnInfo[$column['Field']] = $column['Type'];
        }

        $columnInfoResult->free(); // Free result set

        // Prepare the insert statements
        $insertValues = [];
        $rowCount = 0;
        while ($row = $tableDataResult->fetch_assoc()) {
            $columns = [];
            $values = [];
            foreach ($row as $key => $value) {
                if ($value === null) {
                    $values[] = 'NULL';
                } elseif (preg_match('/\b(?:tinyint|smallint|mediumint|int|bigint|decimal|float|double|real|bit|boolean|serial)\b/i', $columnInfo[$key])) {
                    $values[] = $value;
                } else {
                    $values[] = "'" . $conn->real_escape_string($value) . "'";
                }
                $columns[] = $key;
            }
            $insertValues[] = "(" . implode(', ', $values) . ")";
            $rowCount++;

            if ($rowCount % 100 === 0) {
                echo "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES\n" . implode(",\n", $insertValues) . ";\n";
                flush();
                $insertValues = [];
            }
        }

        if (!empty($insertValues)) {
            echo "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES\n" . implode(",\n", $insertValues) . ";\n\n";
            flush();
        }

        $tableDataResult->free(); // Free result set
    }

    $tablesResult->free(); // Free result set

    ob_end_flush(); // Flush the output buffer
    exit;
}

function exportAsZip($conn, $dbname, $servername)
{
    $zipFileName = $dbname . '_tables.zip'; // Export as zip file with SQL tables separated
    $zip = new ZipArchive(); // Initialize zip archive

    if ($zip->open($zipFileName, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        $log[] = 'Error creating zip file';
        echo json_encode(['status' => 'error', 'message' => 'Error creating zip file', 'log' => $log]);
        exit;
    }

    $tablesResult = $conn->query('SHOW TABLES'); // Fetch all tables in the database
    if (!$tablesResult) {
        $log[] = 'Error fetching tables: ' . $conn->error;
        echo json_encode(['status' => 'error', 'message' => 'Error fetching tables: ' . $conn->error, 'log' => $log]);
        exit;
    }

    // Loop through each table and export it to a separate SQL file
    while ($tableRow = $tablesResult->fetch_row()) {
        $table = $tableRow[0];

        $createTableResult = $conn->query("SHOW CREATE TABLE `$table`"); // Get the table creation query
        if (!$createTableResult) {
            $log[] = 'Error fetching table creation query: ' . $conn->error;
            echo json_encode(['status' => 'error', 'message' => 'Error fetching table creation query: ' . $conn->error, 'log' => $log]);
            exit;
        }
        $createTableRow = $createTableResult->fetch_row();
        $createTableSql = $createTableRow[1] . ";\n\n";

        $tableDataResult = $conn->query("SELECT * FROM `$table`"); // Fetch the table data
        if (!$tableDataResult) {
            $log[] = 'Error fetching table data: ' . $conn->error;
            echo json_encode(['status' => 'error', 'message' => 'Error fetching table data: ' . $conn->error, 'log' => $log]);
            exit;
        }

        $columnsResult = $conn->query("SHOW COLUMNS FROM `$table`"); // Fetch column information for data type handling
        $columnInfo = [];
        $columns = [];
        while ($columnRow = $columnsResult->fetch_assoc()) {
            $columnInfo[$columnRow['Field']] = $columnRow['Type'];
            $columns[] = $columnRow['Field'];
        }

        // Add table header to SQL file
        $sqlContent = <<<EOT
-- MySQL Dump
--
-- Host: {$servername}    Database: {$dbname}
-- ------------------------------------------------------
-- Server version    {$conn->server_info}

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `{$table}`
--

DROP TABLE IF EXISTS `{$table}`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
{$createTableSql}
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `{$table}`
--

LOCK TABLES `{$table}` WRITE;
/*!40000 ALTER TABLE `{$table}` DISABLE KEYS */;

EOT;

        // Prepare insert values
        $insertValues = [];
        $rowCount = 0;
        while ($row = $tableDataResult->fetch_assoc()) {
            $values = [];
            foreach ($row as $key => $value) {
                if ($value === null) {
                    $values[] = 'NULL';
                } elseif (preg_match('/\b(?:tinyint|smallint|mediumint|int|bigint|decimal|float|double|real|bit|boolean|serial)\b/i', $columnInfo[$key])) {
                    $values[] = $value;
                } else {
                    $values[] = "'" . $conn->real_escape_string($value) . "'";
                }
            }
            $insertValues[] = "(" . implode(', ', $values) . ")";
            $rowCount++;

            if ($rowCount % 100 === 0) {
                $sqlContent .= "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES\n" . implode(",\n", $insertValues) . ";\n";
                $insertValues = [];
            }
        }
        if (!empty($insertValues)) {
            $sqlContent .= "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES\n" . implode(",\n", $insertValues) . ";\n";
        }

        $sqlContent .= <<<EOT
/*!40000 ALTER TABLE `{$table}` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

EOT;

        $tableDataResult->free(); // Free result set

        $zip->addFromString("$table.sql", $sqlContent); // Add SQL file to the zip archive
    }

    $tablesResult->free(); // Free result set
    $zip->close(); // Close the zip archive

    // Return the zip file to the client
    if (file_exists($zipFileName)) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($zipFileName) . '"');
        header('Content-Length: ' . filesize($zipFileName));
        readfile($zipFileName);
        unlink($zipFileName); // Remove the zip file after download
        exit;
    } else {
        $log[] = 'Error: Zip file does not exist after creation';
        echo json_encode(['status' => 'error', 'message' => 'Error: Zip file does not exist after creation', 'log' => $log]);
        exit;
    }
}

$conn->close();