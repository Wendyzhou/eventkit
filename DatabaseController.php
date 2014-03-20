<?php
/* ==========================================================================
 * DatabaseController
 * ==========================================================================
 *
 * SUMMARY
 * This class manages the database (reading, writing, etc.). It also handles
 * inserting new POST data into the database when a notification is
 * received.
 *
 */

namespace SendGrid\EventKit;

require_once "Logger.php";
include_once "Constants.php";

use PDO;
use Logger;

class DatabaseController {
    /*

     PROPERTIES

     *===========================================*/
    private $_db = null;

    public static $schemas = array(
        "timestamp" => "INT",
        "event" => "TEXT",
        "email" => "TEXT",
        "smtpid" => "TEXT",
        "sg_event_id" => "TEXT",
        "sg_message_id" => "TEXT",
        "category" => "TEXT",
        "newsletter" => "TEXT",
        "response" => "TEXT",
        "reason" => "TEXT",
        "ip" => "TEXT",
        "useragent" => "TEXT",
        "attempt" => "TEXT",
        "status" => "TEXT",
        "type" => "TEXT",
        "url" => "TEXT",
        "additional_arguments" => "TEXT",
        "event_post_timestamp" => "INT",
        "raw" => "TEXT",
        "uid" => "INTEGER PRIMARY KEY AUTOINCREMENT"
    );


    /*

     PUBLIC FUNCTIONS

     *=========================================================================*/

    /* __construct

     SUMMARY
     Initializes the controller - opens the database;

     PARAMETERS
     None.

     RETURNS
     Nothing.

     ==========================================================================*/
    public function __construct() {
        try {
            $this->_db = new PDO( 'sqlite:'.join( DIRECTORY_SEPARATOR, array( ROOT_DIR, DB_NAME ) ) );
            $this->_db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
        } catch( Exception $e ) {
            Logger::logError( "An error occurred while creating a new database: ".$e->getMessage() );
            die( $e->getMessage() );
        }
    }



    /* createNewDatabase

     SUMMARY
     Creates a new SQLite database and stores the credentials in a separate
     file

     PARAMETERS
     None.

     RETURNS
     Nothing.

     ==========================================================================*/
    public static function createNewDatabase() {
        try {
            // CREATE THE DATABASE FOLDER
            $folder = "db";
            $db_folder_path = join( DIRECTORY_SEPARATOR, array( $folder, uniqid() ) );
            if ( !file_exists( $folder ) and !is_dir( $folder ) ) {
                $oldumask = umask( 0 );
                mkdir( $folder, 0777 );
                mkdir( $db_folder_path, 0777 );
                umask( $oldumask );
            }

            // CREATE THE DATABASE
            $db_name = uniqid().".db";
            $db_path = join( DIRECTORY_SEPARATOR, array( $db_folder_path, $db_name ) );
            $file_db = new PDO( "sqlite:$db_path" );
            chmod( "$db_path", 0777 );
            Logger::logSystem( "Created new $db_name database." );

            // STORE THE CREDENTIALS
            $credentials = "<?php\n    define(\"DB_NAME\", \"$db_path\");\n    define(\"ROOT_DIR\", \"".dirname( __FILE__ )."\")?>";
            $index = "<?php\n?>";
            file_put_contents( "Constants.php", $credentials, FILE_APPEND | LOCK_EX );
            file_put_contents( join( DIRECTORY_SEPARATOR, array( $folder, "index.php" ) ), $index, FILE_APPEND | LOCK_EX );
            file_put_contents( join( DIRECTORY_SEPARATOR, array( $db_folder_path, "index.php" ) ), $index, FILE_APPEND | LOCK_EX );

            // CREATE THE TABLES
            // Each event will have their own table, so first we need to
            // organize the events and all the columns their tables will
            // have.
            $columns = DatabaseController::$schemas;

            // CYCLE THROUGH EACH EVENT AND CREATE THE TABLE
            $table_columns = "";
            foreach ( $columns as $column => $type ) {
                if ( strlen( $table_columns ) > 0 ) $table_columns .= ",\n";
                $table_columns .= "`$column` $type";
            }
            $create_table = "CREATE TABLE IF NOT EXISTS `events` (\n$table_columns\n);";
            $file_db->exec( $create_table );
            Logger::logSystem( "Created table \"events\" in the database." );
        } catch( Exception $e ) {
            // LOG THE ERROR
            Logger::logError( "An error occurred while creating a new database: ".$e->getMessage() );
            die( $e->getMessage() );
        }
    }



    /* processPost

     SUMMARY
     Accepts a JSON string from the webhook POST to parse and insert into the
     database.

     PARAMETERS
     $json      The JSON string from the webhook POST.

     RETURNS
     A string representing the header to indicate to the webhook a success or
     failure.

     ==========================================================================*/
    public function processPost( $json ) {
        $parsed;

        // TRY PARSING THE STRING, THROW AN ERROR IF NEEDED.
        try {
            $parsed = json_decode( $json, true );
        } catch ( Exception $e ) {
            Logger::logError( "An error occurred while parsing the POST JSON: ".$e->getMessage() );
            return "HTTP/1.0 400 Received a body that cannot be parsed as JSON.";
        }

        // CHECK IF AN ARRAY
        // This starter kit uses V3 of the SendGrid Event Webhook, and is
        // expecting an array.  If our parsed data isn't an array, throw
        // an error.

        if ( !is_array( $parsed ) or $this->isAssociativeArray( $parsed ) ) {
            Logger::logError( "The parsed body received is not an indexed array as expected: ".var_export( $parsed, true ) );
            return "HTTP/1.0 400 Received a body that is not an array.";
        }

        // LOOP THROUGH ARRAY
        // We're now going to loop through the array and process each event.
        foreach ( $parsed as $notification ) {
            $this->insertNotification( $notification );
        }

        return "HTTP/1.0 200 Event POST accepted.";
    }



    /* insertNotification

     SUMMARY
     This function takes a single associative array from the webhook POST and
     inserts it into the appropriate table of the database (the database has
     a table for each type of event).

     PARAMETERS
     $notification      An associative array containing the notification info.

     RETURNS
     A BOOL indicating if the notification was successfully inserted into the
     database or not.

     ==========================================================================*/
    public function insertNotification( $notification ) {
        // CHECK EVENT TYPE
        // At the very minimum we need an event parameter.
        if ( !array_key_exists( "event", $notification ) ) {
            Logger::logError( "Could not insert notification into table - missing \"event\" key: ".var_export( $notification, true ) );
            return false;
        }

        // GRAB THE SCHEMA FOR THE EVENT
        // This will give us all the columns in the table.
        $schema = self::$schemas;

        // ARRAY FOR INSERTING INTO TABLE
        // This is the array we'll use to add data to the database. It
        // follows the schema of the target table.
        $table_values = array(
            "event_post_timestamp" => time(),
            "raw" => json_encode( $notification )
        );

        // FIND THE ADDITIONAL ARGUMENTS
        // Find the additional parameters that aren't part of the default
        // parameters (i.e. unique arguments) and group them into their
        // own array. All other arguments will be grouped into a separate
        // array to be used for inserting into the database.
        $additional_arguments = array();

        foreach ( $notification as $key => $value ) {
            $mod_key = str_replace( "-", "" , $key );
            if ( array_key_exists( $mod_key, $schema ) ) {
                if ( $mod_key === "newsletter" or $mod_key === "category" ) {
                    $table_values[$mod_key] = json_encode( $value );
                } else {
                    $table_values[$mod_key] = $value;
                }
            } else {
                $additional_arguments[$mod_key] = $value;
            }
        }

        // ADD ADDITIONAL ARGUMENT ARRAY TO TABLE ARRAY
        // Now that we've separated the additional arguments, convert
        // them to JSON and add the string to the array used to insert
        // data into the table.
        if ( !empty( $additional_arguments ) ) {
            $table_values["additional_arguments"] = json_encode( $additional_arguments );
        }

        // PREP THE SQL STATEMENT
        // Create a string to run as a SQL statement for adding the
        // data into the database.
        $columns = "";
        $values = "";
        $bindings = array();
        foreach ( $table_values as $column => $value ) {
            if ( $column == "uid" ) continue;

            $bindings[":$column"] = $value;

            if ( strlen( $columns ) ) $columns .= ", ";
            $columns .= "`$column`";

            if ( strlen( $values ) ) $values .= ", ";
            $values .= ":$column";
        }
        $sql = "INSERT INTO `events` ($columns) VALUES ($values)";

        // EXECUTE THE SQL STATEMENT
        // Run the command and add the data into the database.
        try {
            $stmt = $this->_db->prepare( $sql );
            if ( $stmt->execute( $bindings ) === FALSE ) {
                Logger::logError( "An error occurred while inserting data into the database: ".var_export( $bindings, true ) );
            }
        } catch ( PDOException $e ) {
            Logger::logError( "An error occurred while inserting data into the database: ".$e->getMessage() );
        }

        return true;
    }


    /* processQuery

     SUMMARY
     This function accepts parameters from a GET post in /api/search.php and
     returns the results as an array.

     PARAMETERS
     $params    The parameters for the search. One of the parameters should be
                'query', which indicates the type of information being searched
                for.

     RETURNS
     An array containing the information found in the search.

     ==========================================================================*/
    public function processQuery( $params ) {
        $response = array();

        if ( array_key_exists( 'query', $params ) ) {

            switch ( $params['query'] ) {
                // RECENT
                // Retrieves the last `n` events where `n` is specified in the
                // `limit` parameter (all events). If `limit` isn't specified,
                // it defaults to 5.
            case 'recent':
                $limit = isset( $params['limit'] ) ? $params['limit'] : 5;
                $sql = 'SELECT * FROM `events` ORDER BY `timestamp` DESC LIMIT '.$limit;
                $statement = $this->_db->prepare( $sql );
                $statement->execute();
                $results = $statement->fetchAll( PDO::FETCH_ASSOC );
                $response = $this->_decodeAllJson( $results );
                break;

                // TOTAL
                // Counts the number of events in the past `n` hours, where
                // `n` is specified in the `hours` parameter. If `hours` isn't
                // defined, then it'll default to 24 hours.
            case 'total':
                $hours = isset( $params['hours'] ) ? $params['hours'] : 24;
                $sql = 'SELECT COUNT(*) FROM `events` WHERE `event_post_timestamp` > '.( time() - ( $hours * 3600 ) );
                $statement = $this->_db->prepare( $sql );
                $statement->execute();
                $results = $statement->fetchAll( PDO::FETCH_ASSOC );
                $response = $results[0]['COUNT(*)'] * 1;
                break;

                // WILDCARD
                // Performs a wildcard search for the parameter `text` in the
                // database.
            case 'wildcard':
                $sql = 'SELECT * FROM `events` WHERE `raw` LIKE \'%'.$params['text'].'%\'ORDER BY `timestamp` DESC';
                $statement = $this->_db->prepare( $sql );
                $statement->execute();
                $results = $statement->fetchAll( PDO::FETCH_ASSOC );
                $response = $this->_decodeAllJson( $results );
                break;

                // EMAIL_STATS
                // Gets the total counts for each event for a specific email.
            case 'email_stats':
                $schema = DatabaseController::$schemas;
                $sql = array();
                $events = array();
                $response = array();
                foreach ( $schema as $key => $value ) {
                    $select = 'SELECT COUNT(`email`) AS count FROM `events` WHERE `email`="'.$params['email'].'" AND `event`="'.$params['event'].'"';
                    array_push( $sql, $select );
                    array_push( $events, $key );
                }
                $all = join( " UNION ALL ", $sql ).";";
                $statement = $this->_db->prepare( join( " UNION ALL ", $sql ) );
                $statement->execute();
                $results = $statement->fetchAll( PDO::FETCH_ASSOC );

                foreach ( $events as $index => $event ) {
                    $response[$event] = $results[$index]["count"] * 1;
                }

                $response['delivery_rate'] = round( ( $response['delivered'] / $response['processed'] ) * 100 );
                $response['open_rate'] = round( ( $response['open'] / $response['delivered'] ) * 100 );
                $response['click_rate'] = round( ( $response['click'] / $response['delivered'] ) * 100 );

                break;

                // DETAILED SEARCH
                // Performs a search with multiple parameters. The only required
                // parameter is `match`, which specifies if all of the search
                // parameters have to be met, or any of them.
            case 'detailed':
                if ( isset( $params['match'] ) ) {
                    $match = ' OR ';
                    if ( $params['match'] === 'all' ) $match = ' AND ';
                    $fields = array();
                    foreach ( $params as $key => $value ) {
                        switch ( $key ) {
                        default:
                            if ( is_array( $value ) ) {
                                $filter = array();
                                foreach ( $value as $item ) {
                                    array_push( $filter, "`$key` LIKE '%$item%'" );
                                }
                                $entry = "(".join( " OR ", $filter ).")";
                                array_push( $fields, $entry );
                            } else {
                                array_push( $fields, "`$key` LIKE '%$value%'" );
                            }
                            break;

                        case 'dateStart':
                            array_push( $fields, '`timestamp` >= '.$value );
                            break;

                        case 'dateEnd':
                            array_push( $fields, '`timestamp` <= '.$value );
                            break;

                        case 'match':
                        case 'query':
                        case 'resultsPerPage':
                        case 'csv':
                            break;
                        }
                    }
                    $sql = 'SELECT * FROM `events` WHERE '.join( $match, $fields ).' ORDER BY `timestamp` DESC';
                    $statement = $this->_db->prepare( $sql );
                    $statement->execute();
                    $results = $statement->fetchAll( PDO::FETCH_ASSOC );
                    $response = $this->_decodeAllJson( $results );
                }
                break;
            }
        }

        return $response;
    }



    /*

      PRIVATE FUNCTIONS

     *=========================================================================*/

    /* isAssociativeArray

     SUMMARY
     Determines if the passed variable is an associative array or not.

     PARAMETERS
     $a     An array.

     RETURNS
     A BOOL indicating if the passed array is an associative array or not.

     ==========================================================================*/
    private function isAssociativeArray( $a ) {
        foreach ( array_keys( $a ) as $key )
            if ( !is_int( $key ) ) return true;
            return false;
    }



    /* _decodeAllJson

     SUMMARY
     Some of the entries in the database are JSON strings, so when we return
     data from the database, we'll want to convert these into arrays so that
     it isn't a string upon return.

     PARAMETERS
     $object    The object to process (could be an array or string).

     RETURNS
     The decoded object (an array);

     ==========================================================================*/
    private function _decodeAllJson( $object ) {
        if ( is_string( $object ) ) {
            // OBJECT IS A STRING - DECODE IT AND RETURN.
            $decoded = json_decode( $object, true, 512 );
            if ( $decoded ) return $decoded;
            else return $object;
        } else if ( $this->isAssociativeArray( $object ) ) {
                $new_object = array();
                foreach ( $object as $key=>$value ) {
                    $new_val = $this->_decodeAllJson( $value );
                    $new_object[$key] = $new_val;
                }
                return $new_object;
            } else if ( is_array( $object ) ) {
                $new_object = array();
                foreach ( $object as $value ) {
                    $new_val = $this->_decodeAllJson( $value );
                    array_push( $new_object, $new_val );
                }
                return $new_object;
            } else {
            return $object;
        }
    }
}

?>
