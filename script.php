<?php

$mysql_host = "";
$mysql_user = "";
$mysql_pass = "";
$mysql_db = "";

//Connect to database
$mysqli = new mysqli($mysql_host, $mysql_user, $mysql_pass, $mysql_db);
if ($mysqli->connect_errno) {
    echo "Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error;
}
echo "Connected to " . $mysqli->host_info . "\n";

//Get course IDs in mdl_enrol -> $courseid[]
$courseres = $mysqli->query("SELECT DISTINCT courseid FROM mdl_enrol ORDER BY courseid ASC");

$courseres->data_seek(0);
while ($row = $courseres->fetch_assoc()) {
	$courseid[] = $row['courseid']; //Add each unique courseid to $courseid array
	echo "Adding courseid " . $row['courseid'] . " to array \n";
}

echo "Found " . count($courseid) . " unique courseids in mdl_enrol \n";

unset($courseres); //Destroy $courseres result to save memory

//Get enrol instances per course -> $enrol_instances[]
for ($c = 0; $c < count($courseid); $c++) {
	echo "Fetching enrolids for courseid " . $courseid[$c] . "\n";
	$enrolres = $mysqli->query("SELECT id, enrol FROM mdl_enrol WHERE courseid = $courseid[$c] ORDER BY id ASC");

	$enrol_instances[$courseid[$c]]['courseid'] = $courseid[$c];

	$enrolres->data_seek(0);
	$row = $enrolres->fetch_assoc(); //First Row (Self Enrol)
	if (!$row['id']) {
		echo "ERROR: No enrol instances found for courseid " . $courseid[$c] . "\n";
		continue; //If nothing is being returned, skip getting enrol instances for the course. This shouldn't happen, though.
	}
	if ($row['enrol'] == "guest") {
		echo "ERROR: First enrol instance is guest for courseid " . $courseid[$c] . "\n";
		continue; //If the first instance returned is a guest enrol type, this might be a hidden course.
	}
	$enrol_instances[$courseid[$c]]['self_enrolid'] = $row['id'];
	echo "Courseid " . $courseid[$c] . " self_enrolid " . $row['id'] . " added\n";
	$enrolid = $row['id']; //PHP doesnt like $row['id'] in the query string - confusing ''
	$mysqli->query("UPDATE mdl_enrol SET enrol = 'self' WHERE id = $enrolid AND courseid = $courseid[$c]"); //Set to self enrol type

	$enrolres-> data_seek(1);
	$row = $enrolres->fetch_assoc(); //Second Row (Guest Enrol)
	$enrolid = $row['id'];
	$mysqli->query("UPDATE mdl_enrol SET enrol = 'guest' WHERE id = $enrolid AND courseid = $courseid[$c]"); //Set to guest enrol type

	$enrolres-> data_seek(2);
	$row = $enrolres->fetch_assoc(); //Third Row (Manual Enrol)
	$enrolid = $row['id'];
	$mysqli->query("UPDATE mdl_enrol SET enrol = 'manual' WHERE id = $enrolid AND courseid = $courseid[$c]"); //Set to manual enrol type

	if ($enrolres->data_seek(3)) {
		$row = $enrolres->fetch_assoc(); //Fourth Row (Database Enrol)
		$enrol_instances[$courseid[$c]]['db_enrolid'] = $row['id'];
		echo "Courseid " . $courseid[$c] . " db_enrolid " . $row['id'] . " added\n";
		$enrolid = $row['id'];
		$mysqli->query("UPDATE mdl_enrol SET enrol = 'database' WHERE id = $enrolid AND courseid = $courseid[$c]"); //Set to database enrol type
	}
	else {
		echo "No db_enrolid found for courseid " . $courseid[$c] . "\n";
	}
	

	//Get the extra database enrolids and add to array
	if ($enrolres->data_seek(4)) {
		while ($row = $enrolres->fetch_assoc()) {
			$enrol_instances[$courseid[$c]]['extra_enrolid'][] = $row['id'];
			$enrolid = $row['id'];
			$courseidint = $courseid[$c];
			echo "Courseid " . $courseid[$c] . " extra_enrolid " . $row['id'] . " added\n";
			$mysqli->query("UPDATE mdl_enrol SET enrol = 'database' WHERE id = $enrolid AND courseid = $courseidint"); //Set to database enrol type
		}
	}
	else {
		echo "No extra enrolids found for courseid " . $courseid[$c] . "\n";
	}

}

unset($enrolres); //Destroy $enrolres result to save memory

//Delete extra enrolids from mdl_enrol for each course
for ($c = 0; $c < count($courseid); $c++) {
	if ($enrol_instances[$courseid[$c]]['extra_enrolid']) {
		echo "Deleting extra enrolids for courseid " . $courseid[$c] . "\n";
		foreach ($enrol_instances[$courseid[$c]]['extra_enrolid'] as $id) {
			if ($mysqli->query("DELETE FROM mdl_enrol WHERE id = $id")) {
				echo "Successfully deleted enrolid " . $id . "\n";
			}
			else
			{
				echo "ERROR: Unable to delete enrolid " . $id . "for " . $courseid[$c] . "|" . $mysqli->errno . ": " . $mysqli->error . "\n";
			}
		}
	}
	else {
		echo "No extra enrolids for courseid " . $courseid[$c] . "\n";
	}
}

//Delete extra enrolids from mdl_user_enrolments
for ($c = 0; $c < count($courseid); $c++) {
	if ($enrol_instances[$courseid[$c]]['extra_enrolid']) {
		echo "Deleting extra user enrolments matching extra enrolids for courseid " . $courseid[$c] . "\n";
		foreach ($enrol_instances[$courseid[$c]]['extra_enrolid'] as $id) {
			if ($mysqli->query("DELETE FROM mdl_user_enrolments WHERE enrolid = $id")) {
				echo "Successfully deleted user enrolment with enrolid " . $id . "\n";
			}
			else
			{
				echo "ERROR: Unable to delete user enrolment with enrolid " . $id . "for " . $courseid[$c] . "|" . $mysqli->errno . ": " . $mysqli->error . "\n";
			}
		}
	}
	else {
		echo "No extra enrolids for courseid " . $courseid[$c] . "\n";
	}
}

//Convert user enrolments with db enrolids to self enrolids
for ($c = 0; $c < count($courseid); $c++) {
	echo "Updating user enrolments for courseid " . $courseid[$c] . "\n";
	$self_enrolid = $enrol_instances[$courseid[$c]]['self_enrolid'];
	$db_enrolid = $enrol_instances[$courseid[$c]]['db_enrolid'];
	if ($self_enrolid && $db_enrolid) {
		if($mysqli->query("UPDATE IGNORE mdl_user_enrolments SET enrolid = $self_enrolid WHERE enrolid = $db_enrolid")) { //Ignores duplicate key entries - some db enrols will be left over.
			echo "Successfully updated database enrolments for courseid " . $courseid[$c] . " to self enrolments\n";
		}
		else {
			echo "ERROR: Could not update database enrolments for courseid " . $courseid[$c] . " to self enrolments." . "|" . $mysqli->errno . ": " . $mysqli->error . "\n";
		}
	}
	else {
		if (!$self_enrolid) {
			echo "No self_enrolid found for courseid " . $courseid[$c] . "\n";
		}
		if (!$db_enrolid) {
			echo "No db_enrolid found for courseid " . $courseid[$c] . "\n";
		}
	}

}

//Clean up duplicate db enrols
for ($c = 0; $c < count($courseid); $c++) {
	echo "Deleting duplicate database user enrolments for courseid " . $courseid[$c] . "\n";
	$self_enrolid = $enrol_instances[$courseid[$c]]['self_enrolid'];
	$db_enrolid = $enrol_instances[$courseid[$c]]['db_enrolid'];
	if ($self_enrolid && $db_enrolid) {
		if($mysqli->query("DELETE FROM mdl_user_enrolments WHERE enrolid = $db_enrolid")) {
			echo "Successfully deleted duplicate database user enrolments for courseid " . $courseid[$c] . "\n";
		}
		else {
			echo "ERROR: Unable to delete duplicate database user enrolments with enrolid " . $db_enrolid . " for courseid" . $courseid[$c] . "|" . $mysqli->errno . ": " . $mysqli->error . "\n";
		}
	}
	else {
		if (!$self_enrolid) {
			echo "No self_enrolid found for courseid " . $courseid[$c] . "\n";
		}
		if (!$db_enrolid) {
			echo "No db_enrolid found for courseid " . $courseid[$c] . "\n";
		}
	}

}

?>