<?php
// MAKING SURE THE REQUEST METHOD IS A POST AND HAS THE EXPECTED PARAMETERS
if( $_SERVER["REQUEST_METHOD"] == "POST" &&
	isset($_POST["phone"]) && trim($_POST["phone"]) != "" &&
	isset($_POST["language"]) && trim($_POST["language"]) != "" &&
	isset($_POST["app_version_code"]) && trim($_POST["app_version_code"]) != "" ) {

	//CALLING THE CONFIGURATION FILE
	require_once("config.php");
	
	// SETTING DEVELOPMENT MODE IF NEED BE
	$GLOBALS["USAGE_MODE_IS_LIVE"] = true;
	if(isset($_POST["phone"]) && trim($_POST["phone"]) != "" && DEVELOPER_USING_LIVE_MODE !== true){
		$ALL_DEVELOPER_POTTNAMES = explode(",", DEVELOPER_USAGE_PHONES);
		if (in_array(trim($_POST["phone"]), $ALL_DEVELOPER_POTTNAMES)){
			$GLOBALS["USAGE_MODE_IS_LIVE"] = DEVELOPER_USING_LIVE_MODE;
		}
	}
	//CALLING THE INPUT VALIDATOR CLASS
	include_once 'classes/input_validation_class.php';
	//CALLING THE MISCELLANOUS CLASS
	include_once 'classes/miscellaneous_class.php';
	//CALLING TO THE DATABASE CLASS
	include_once 'classes/db_class.php';
	//CALLING TO THE PREPARED STATEMENT QUERY CLASS
	include_once 'classes/prepared_statement_class.php';
	//CALLING TO THE SUPPORTED LANGUAGES CLASS
	include_once 'classes/languages_class.php';
	//CALLING TO THE TIME CLASS
	include_once 'classes/time_class.php';


	// INITIALIZING VARIABLES TO HOLD THE INPUTS
	$input_phone = trim($_POST["phone"]);
	$input_language = trim($_POST["language"]);
	$input_app_version_code = intval($_POST["app_version_code"]);

	// CREATING A VALIDATOR OBJECT TO BE USED FOR VALIDATIONS
	$validatorObject = new inputValidator();
	// CREATING A LANGUAGES OBJECT TO BE USED TO RETRIEVE STRINGS NEEDED FOR RESPONSES
	$languagesObject = new languagesActions();
	// CREATING FRONT-END RESPONDER OBJECT
	$miscellaneousObject = new miscellaneousActions();
	// CREATING TIME OPERATOR OBJECT
	$timeOperatorObject = new timeOperator();

	// MAKING SURE THE PHONE NUMBER CONFORM TO MAXLENGTH
	if($validatorObject->stringIsNotMoreThanMaxLength($input_phone, 15)){
		$miscellaneousObject->respondFrontEnd1($languagesObject->getLanguageString("error", $input_language), $languagesObject->getLanguageString("request_failed", $input_language));
	}

	//MAKING SURE THAT PHONE NUMBER CONTAINS NO TAGS
	if($validatorObject->stringContainsNoTags($input_phone) !== true){
		$miscellaneousObject->respondFrontEnd1($languagesObject->getLanguageString("error", $input_language), $languagesObject->getLanguageString("request_failed", $input_language));
	}
	
	// MAKING SURE PHONE NUMBER AFTER + CONTAINS ONLY NUMBERs
	if($validatorObject->inputContainsOnlyNumbers(substr($input_phone,1,strlen($input_phone))) !== true){
		$miscellaneousObject->respondFrontEnd1($languagesObject->getLanguageString("error", $input_language), $languagesObject->getLanguageString("request_failed", $input_language));
	}

	//MAKING SURE THE FIRST LETTER IS '+'
	if(substr($input_phone,0,1) != "+"){
		$miscellaneousObject->respondFrontEnd1($languagesObject->getLanguageString("error", $input_language), $languagesObject->getLanguageString("request_failed", $input_language));
	}

	// CREATING DATABASE CONNECTION OBJECT
	$dbObject = new dbConnect();

	if($dbObject->connectToDatabase(0, $GLOBALS["USAGE_MODE_IS_LIVE"]) === false){
		$miscellaneousObject->respondFrontEnd1($languagesObject->getLanguageString("error", $input_language), $languagesObject->getLanguageString("request_failed", $input_language));
	}

	// CREATING PREPARED STATEMENT QUERY OBJECT
	$preparedStatementObject = new preparedStatement();
	$prepared_statement = $preparedStatementObject->prepareAndExecuteStatement($dbObject->connectToDatabase(0, $GLOBALS["USAGE_MODE_IS_LIVE"]), "SELECT last_sms_sent_datetime, number_verification_code, flag, number_verified FROM " . LOGIN_TABLE_NAME . " WHERE number_login = ? AND (last_sms_sent_datetime = '0000-00-00 00:00:00' OR last_sms_sent_datetime < NOW() - INTERVAL 10 MINUTE)", 1, "s", array($input_phone));
	if($prepared_statement === false){
		$miscellaneousObject->respondFrontEnd1($languagesObject->getLanguageString("error", $input_language), $languagesObject->getLanguageString("request_failed_try_resending_the_code_in_10_mins", $input_language));
	}
	// GETTING RESULTS OF QUERY INTO AN ARRAY
	$prepared_statement_results_array = $preparedStatementObject->getPreparedStatementQueryResults($prepared_statement, array("last_sms_sent_datetime", "number_verification_code", "flag", "number_verified"), 4, 1);

	if($prepared_statement_results_array === false){
		$miscellaneousObject->respondFrontEnd1($languagesObject->getLanguageString("error", $input_language), $languagesObject->getLanguageString("request_failed_try_resending_the_code_in_10_mins", $input_language));
	}
	// IF THE DATABASE QUERY GOT NO RESULTS
	if($prepared_statement_results_array[3] != -1){
		$miscellaneousObject->respondFrontEnd1($languagesObject->getLanguageString("error", $input_language), $languagesObject->getLanguageString("request_failed", $input_language));
	} else if(trim($prepared_statement_results_array[0]) == "last_sms_sent_datetime"){
		$miscellaneousObject->respondFrontEnd1($languagesObject->getLanguageString("error", $input_language), $languagesObject->getLanguageString("request_failed_try_resending_the_code_in_10_mins", $input_language));
	} else if (trim($prepared_statement_results_array[1]) == "number_verification_code" || trim($prepared_statement_results_array[1]) == ""){
			$miscellaneousObject->respondFrontEnd1($languagesObject->getLanguageString("error", $input_language), $languagesObject->getLanguageString("request_failed", $input_language));
	}


	// CHECKING IF YOUR ACCOUNT IS SUSPENDED OR NOT
	if($prepared_statement_results_array[2] != 0){
		$miscellaneousObject->respondFrontEnd1("0", $languagesObject->getLanguageString("your_account_has_been_suspended", $input_language));
	}

	if(trim($prepared_statement_results_array[1]) != ""){

/*****************************************************************************************************************
				

				SEND VERIFICATION SMS HERE. MAKE SURE THERE IS NO DATE IN THE DATABASE OR THE DATE IS PAST 24 HOURS



******************************************************************************************************************/

		// UPDATING THE RESET DATE, THE PASSWORD
		$prepared_statement = $preparedStatementObject->prepareAndExecuteStatement($dbObject->connectToDatabase(0, $GLOBALS["USAGE_MODE_IS_LIVE"]), "UPDATE " . LOGIN_TABLE_NAME . " SET last_sms_sent_datetime = ?, number_verified = -1 WHERE number_login = ?", 2, "ss", array(date("Y-m-d H:i:s"), $input_phone));

		// CLOSE DATABASE CONNECTION
		$dbObject->closeDatabaseConnection($prepared_statement);
		if($prepared_statement === false){
			$miscellaneousObject->respondFrontEnd1($languagesObject->getLanguageString("error", $input_language), $languagesObject->getLanguageString("request_failed", $input_language));
		}
		$miscellaneousObject->respondFrontEnd1("yes", $languagesObject->getLanguageString("a_new_code_has_been_sent_to_your_phone", $input_language));
	} else {
		// CLOSE DATABASE CONNECTION
		$dbObject->closeDatabaseConnection($prepared_statement);
		$miscellaneousObject->respondFrontEnd1($languagesObject->getLanguageString("error", $input_language), $languagesObject->getLanguageString("request_failed", $input_language));
	}

}