<?php

// This file is part of the Accredible Certificate module for Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Certificate module core interaction API
 *
 * @package    mod
 * @subpackage accredible
 * @copyright  Accredible <dev@accredible.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * List all of the ceritificates with a specific achievement id
 *
 * @param string $achievement_id
 * @return array[stdClass] $certificates
 */
function accredible_get_issued($achievement_id) {
    global $CFG;

    $curl = curl_init('https://api.accredible.com/v1/credentials?full_view=true&achievement_id='.urlencode($achievement_id));
    curl_setopt( $curl, CURLOPT_HTTPHEADER, array( 'Authorization: Token token="'.$CFG->accredible_api_key.'"' ) );
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    $result = json_decode( curl_exec($curl) );
    curl_close($curl);
    return $result->credentials;
}

/*
 * accredible_issue_default_certificate
 * 
 */
function accredible_issue_default_certificate($certificate_id, $name, $email, $grade, $quiz_name) {
    global $DB, $CFG;

    // Issue certs
    $accredible_certificate = $DB->get_record('accredible', array('id'=>$certificate_id));

    $certificate = array();
    $certificate['name'] = $accredible_certificate->name;
    $certificate['achievement_id'] = $accredible_certificate->achievementid;
    $certificate['description'] = $accredible_certificate->description;
    $certificate['recipient'] = array('name' => $name, 'email'=> $email);
    $certificate['evidence_items'] = array( array('string_object' => $grade, 'description' => $quiz_name, 'custom'=> true, 'category' => 'grade' ));

    $curl = curl_init('https://api.accredible.com/v1/credentials');
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query( array('credential' => $certificate) ));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt( $curl, CURLOPT_HTTPHEADER, array( 'Authorization: Token token="'.$CFG->accredible_api_key.'"' ) );
    curl_exec($curl);
    curl_close($curl);
}

/*
 * Quiz submission handler (checks for a completed course)
 *
 * @param core/event $event quiz mod attempt_submitted event
 */
function accredible_quiz_submission_handler($event) {
    global $DB, $CFG;
    require_once($CFG->dirroot . '/mod/quiz/lib.php');

    $accredible_certificate = $DB->get_record('accredible', array('course' => $event->courseid));
    // check for the existance of a certificate and an auto-issue rule
    if( $accredible_certificate and $accredible_certificate->finalquiz ) {
        $attempt = $event->get_record_snapshot('quiz_attempts', $event->objectid);
        $quiz    = $event->get_record_snapshot('quiz', $attempt->quiz);

        // check which quiz is used as the deciding factor in this course
        if($quiz->id == $accredible_certificate->finalquiz) {
            $certificates = accredible_get_issued($accredible_certificate->achievementid);
            $user = $DB->get_record('user', array('id' => $event->relateduserid));
            $certificate_exists = false;

            foreach ($certificates as $certificate) {
                if($certificate->recipient->email == $user->email) {
                    $certificate_exists = true;
                }
            }

            // check for an existing certificate
            if(!$certificate_exists) {
                $users_grade = ( quiz_get_best_grade($quiz, $user->id) / $quiz->grade ) * 100;
                $grade_is_high_enough = ($users_grade >= $accredible_certificate->passinggrade);

                // check for pass
                if($grade_is_high_enough) {
                    // issue a ceritificate
                    accredible_issue_default_certificate( $accredible_certificate->id, fullname($user), $user->email, (string) $users_grade, $quiz->name);
                }
            }
        }
    }
}