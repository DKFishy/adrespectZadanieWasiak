<?php
//setting timezone for compatability
date_default_timezone_set("Europe/Warsaw");

//setting up HTML information and files
?> 

<!DOCTYPE html>
<html>
<head>
    <title>Kowerter walut</title>
    <link rel="stylesheet" type="text/css" href="styles.css">
</head>
<body>

<?php
//DB connection class
class DbConnection {
    private $servername;
    private $username;
    private $password;
    private $db_name;
    private $connection;

    public function __construct($servername, $username, $password, $db_name){

        $this->servername = $servername;
        $this->username = $username;
        $this->password = $password;
        $this->db_name = $db_name;
    }

    public function connect() {
        $this->connection = mysqli_connect($this->servername, $this->username, $this->password, $this->db_name);
        if(!$this->connection) {
            die("Database connection error: " . mysqli_connect_error());
        }
        mysqli_set_charset($this->connection, "utf8mb4");//ensures proper render of accented characters 
    }

    public function getConnection() {
        return $this->connection;
    }

    public function close() {
        mysqli_close($this->connection);
    }

}

//API data retrieving class
class JsonAPI {
    private $apiURL;
    private $jsonData;
    private $jsonDate;

    public function __construct($apiURL) {
        $this->apiUrl = $apiURL;
    }
    public function getData() {
        $json = file_get_contents($this->apiUrl);
        $this->jsonData = json_decode($json, true);
        $this->jsonDate = $this->jsonData[0]['effectiveDate'];
    }

    public function getRates() {
        if ($this->jsonData){
            return $this->jsonData[0]['rates'];
        }else{
            return [];
        }
    }
    
    public function getDate() {
        if ($this->jsonData) {
            return $this->jsonData[0]['effectiveDate'];
        }else{
            return null;
        }
    }
}

//Class for checking and inserting API data to database
class DataHandler {
    private $dbConnection;
    private $connection;
    private $currenciesSelectStatement;
    private $recordsSelectStatement;

    //prepare statement for currencies
    public function __construct($dbConnection) {
        $this->dbConnection = $dbConnection;
        $this->connection = $this->dbConnection->getConnection();
        $this->currenciesSelectStatement = $this->connection->prepare("SELECT * FROM rates;");
        $this->recordsSelectStatement = $this->connection->prepare("SELECT `currency1`, `currency2`, `input`, `result` FROM conversions ORDER By id DESC LIMIT 5;");  
    }

    //query currency table
    public function prepareCurrencies() {
        $this->currenciesSelectStatement->execute();
        $queryResult = $this->currenciesSelectStatement->get_result();
        
        return $queryResult;
    }

    public function getCurrencies ($date, $rates) {
        //check if table has any data and if date in API response is different from today
        //get database query
        $sqlCheckResult = $this->prepareCurrencies();

        if($date != date("Y-m-d") && $sqlCheckResult->num_rows != 0){
            $sqlClear = "TRUNCATE TABLE rates;";
            $truncateStatement = $this->connection->prepare($sqlClear);
            $truncateStatement->execute();
            $truncateStatement->close();
            $this->selectStatement->execute();
            $sqlCheckResult = $this->prepareCurrencies();
        }

        //check if table is empty
        if($sqlCheckResult->num_rows == 0){
            $sqlInsertCurrency = "INSERT INTO rates (code, currency, rate) VALUES (?, ?, ?);";
            $insertStatement = $this->connection->prepare($sqlInsertCurrency);
            $insertStatement->bind_param("sss", $code, $currency, $value);

            foreach ($rates as $rate) {
                $code = $rate['code'];
                $currency = $rate['currency'];
                $value = $rate['mid'];
                $insertStatement->execute();
            }
            $insertStatement->close();
        }
    }

    public function prepareRecords() {
        $this->recordsSelectStatement->execute();
        $queryResult = $this->recordsSelectStatement->get_result();

        return $queryResult;
    }

    public function updateRecords($currency1, $currency2, $inputValue, $convertedAmount) {
        $insertStatement = $this->connection->prepare("INSERT INTO conversions (currency1, currency2, input, result) VALUES (?, ?, ?, ?);");
        $insertStatement->bind_param("ssss", $currency1, $currency2, $inputValue, $convertedAmount);
        $insertStatement->execute();
        $insertStatement->close();
    }

    public function __destruct() {
        $this->currenciesSelectStatement->close();
        $this->recordsSelectStatement->close();
    }
}


//class that creates table and populates it currency data from database
class TableCreator {
    private $dataHandler;
    private $currenciesTableData;
    private $recordsTableData;

    public function __construct(DataHandler $dataHandler){
        $this->dataHandler = $dataHandler;
        $this->currenciesTableData = $this->dataHandler->prepareCurrencies()->fetch_all(MYSQLI_ASSOC);
        $this->recordsTableData = $this->dataHandler->prepareRecords()->fetch_all(MYSQLI_ASSOC);
    }

    public function createRatesTable() {
        if (!empty($this->currenciesTableData)) {
    
            echo "<table class='left'>";
            echo "<thead>";
            echo "<tr>";

            // Generate table headers dynamically
            foreach ($this->currenciesTableData[0] as $columnName => $value) {
                echo "<th>" . ucfirst($columnName) . "</th>";
            }

            echo "</tr>";
            echo "</thead>";
            echo "<tbody>";

            foreach ($this->currenciesTableData as $row) {
                echo "<tr>";
                foreach ($row as $value) {
                    $formattedValue = rtrim(rtrim($value, '0'), '.');
                    echo "<td>" . $formattedValue . "</td>";
                }
                echo "</tr>";
            }

            echo "</tbody>";
            echo "</table>";
        } else {
            echo "No data.";
        }
    }
    public function createRecordsTable() {
        if (!empty($this->recordsTableData)) {
            echo "<table class='right bottom'>";
            echo "<thead>";
            echo "<tr>";

            foreach ($this->recordsTableData[0] as $columnName => $value) {
                echo "<th>" . ucfirst($columnName) . "</th>";
            }

            foreach ($this->recordsTableData as $row) {
                echo "<tr>";
                foreach ($row as $value) {
                    echo "<td>" . $value . "</td>";
                }
                echo "</tr>";
            }

            echo "</tbody>";
            echo "</table>";
        } else {
            echo "No data.";
        }
    }
}

//Form building class
class FormGenerator {
    private $dataHandler;

    public function __construct(DataHandler $dataHandler) {
        $this->dataHandler = $dataHandler;
    }

    public function generateForm() {
        $currencies = $this->dataHandler->prepareCurrencies()->fetch_all(MYSQLI_ASSOC);

        echo '<form method="POST" class="right top">';
        
        echo '<label for="currency1">Currency 1: </label>';
        echo '<select name="currency1" id="currency1">';
        foreach ($currencies as $currency) {
            echo '<option value="' . $currency['code'] . '|' .$currency['rate'] . '">' . $currency['currency'] . '</option>';
        }
        echo '</select>';
        
        echo '<label for="currency2">Currency 2: </label>';
        echo '<select name="currency2" id="currency2">';
        foreach ($currencies as $currency) {
            echo '<option value="' . $currency['code'] . '|' .$currency['rate'] . '">' . $currency['currency'] . '</option>';
        }
        echo '</select>';
        
        echo '<label for="inputValue">value: </label>';
        echo '<input type="number" name="inputValue" min="0" id="inputValue">';
        
        echo '<input type="submit" Value="Submit">';
        
        echo '</form>';
    }
}

//currency calculator class
class Converter {
    public function convert($currency1, $currency2, $inputValue) {
        $convertedAmount = $currency1 / $currency2 * $inputValue;
        return $convertedAmount;
    }
}

//class for processing form data and passing it to converter
class FormConverter {
    private $formGenerator;
    private $converter;
    private $dataHandler;

    public function __construct(FormGenerator $formGenerator, Converter $converter, DataHandler $dataHandler)  {
        $this->formGenerator = $formGenerator;
        $this->converter = $converter;
        $this->dataHandler = $dataHandler;
    }

    public function handleForm() {
        $this->formGenerator->generateForm();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $currency1 = explode('|', $_POST['currency1']);
            $currency2 = explode('|', $_POST['currency2']);
            $inputValue = $_POST['inputValue'];

            $convertedAmount = $this->converter->convert(floatval($currency1[1]), floatval($currency2[1]), $inputValue);

            echo '<div class="right mid">Converted amount: ' . $convertedAmount . '</div>';

            $this->dataHandler->updateRecords($currency1[0], $currency2[0], $inputValue, $convertedAmount);
        }
    }
}

//Calling the functions and assigning related variables
$servername = ".";
$username = ".";
$password = ".";//to be filled with on server
$db_name = "currencies";
$apiurl = "http://api.nbp.pl/api/exchangerates/tables/a/";

$dbConnection = new DbConnection($servername, $username, $password, $db_name);
$dbConnection->connect();

$jsonAPI = new JsonAPI($apiurl);
$jsonAPI->getData();
$rates = $jsonAPI->getRates();
$date = $jsonAPI->getDate();

$dataHandler = new Datahandler($dbConnection);
$dataHandler->getCurrencies($date, $rates);

$tableCreator = new TableCreator($dataHandler);
$tableCreator->createRatesTable();

$formGenerator = new FormGenerator($dataHandler);

$converter = new Converter();

$formConverter = new FormConverter($formGenerator, $converter, $dataHandler);
$formConverter->handleForm();

$tableCreator->createRecordsTable();

$dbConnection->close();
