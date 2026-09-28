<?php
namespace portalikaikabim\services\projectEstimator;
use Exception;

//-------------------------------------------------------------------
//                          SERVICES
//-------------------------------------------------------------------
class AirtableService {
    private $baseId;
	private $transactionTrackerBaseId;
    private $calendarBaseId;
    private $apiKey = PROJEST_API_KEY;

    public function __construct(string $baseId, array $config = []) {
    	$this->baseId = $baseId;
    	$this->transactionTrackerBaseId = $config['transactionTrackerBaseId'] ?? null;
    	$this->calendarBaseId = $config['calendarBaseId'] ?? null;
	}
	
	function fetchAirtableRecords($tableId,$filter = null,$fields = null,$returnFieldsByFieldId = false,$maxRecords = null) {
    	$records = [];
    	$offset = null;
		try{
			do {
        		$url = "https://api.airtable.com/v0/{$this->baseId}/{$tableId}";

				// Build query params - all parts use rawurlencode (spaces as %20) so encoding is
				// consistent throughout; http_build_query() encodes spaces as + instead, which
				// mixed with the fields[] entries below could reach Airtable inconsistently
				$queryParts = [];
				// fields[] must repeat without indices - Airtable expects fields[]=A&fields[]=B, not fields[0]=A
				if (!empty($fields) && is_array($fields)) {
    				foreach ($fields as $field) {
        				$queryParts[] = 'fields[]=' . rawurlencode($field);
    				}
				}
				if (!empty($offset)) {
    				$queryParts[] = 'offset=' . rawurlencode($offset);
				}
				if (!empty($filter)) {
    				$queryParts[] = 'filterByFormula=' . rawurlencode($filter); // example filter
				}
				// optional cap on the TOTAL records returned; Airtable stops sending an offset once it is
				// reached, so the pagination loop below stops early instead of walking the whole table
				if (!empty($maxRecords)) {
    				$queryParts[] = 'maxRecords=' . (int) $maxRecords; // cast: an int never needs encoding
				}
				if (!empty($returnFieldsByFieldId)) {
    				$queryParts[] = 'returnFieldsByFieldId=true'; // key the response by field ID instead of field name
				}
				if (!empty($queryParts)) {
    				$url .= '?' . implode('&', $queryParts);
				}

        		$ch = curl_init($url);
        		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        		curl_setopt($ch, CURLOPT_HTTPHEADER, [
            		"Authorization: Bearer $this->apiKey"
        		]);

        		$responseRaw = curl_exec($ch);
        		if ($responseRaw === false) {
            		$error = curl_error($ch);
            		curl_close($ch);
            		throw new Exception("cURL error: $error");
        		}

        		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        		curl_close($ch);

        		if ($httpCode !== 200) {
            		throw new Exception("Airtable API returned HTTP status $httpCode with body: $responseRaw");
        		}

        		$response = json_decode($responseRaw, true);
        		if (!isset($response['records'])) {
            		throw new Exception("Invalid response structure: " . print_r($response, true));
        		}

        		$records = array_merge($records, $response['records']);
        		$offset = $response['offset'] ?? null;

    		} while ($offset);

    		return [
            	'success' => true,
            	'records' => $records
        	];
		}
		catch (Exception $e) {
        	error_log("[Airtable] " . $e->getMessage());
        	return [
            	'success' => false,
            	'error'   => $e->getMessage()
        	]; // return empty array on failure
    	}
	}
	public function createAirtableRecords($tableId, $records) {
    $apiUrl = "https://api.airtable.com/v0/{$this->baseId}/{$tableId}";
    $results = [];

    try {
        // Airtable accepts max 10 records per request
        $chunks = array_chunk($records, 10);

        foreach ($chunks as $chunk) {
            $recordsWrapped = array_map(function($rec) {
    			// if 'fields' already exists, leave it
    			return isset($rec['fields']) ? $rec : ['fields' => $rec];
			}, $chunk);

			$payload = json_encode(['records' => $recordsWrapped]);

            $ch = curl_init($apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer $this->apiKey",
                "Content-Type: application/json"
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

            $responseRaw = curl_exec($ch);
            if ($responseRaw === false) {
                $error = curl_error($ch);
                curl_close($ch);
                throw new Exception("cURL error: $error");
            }

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                throw new Exception("Airtable API returned HTTP status $httpCode with body: $responseRaw");
            }

            $response = json_decode($responseRaw, true);
            if (!isset($response['records'])) {
                throw new Exception("Invalid response structure: " . print_r($response, true));
            }

            $results = array_merge($results, $response['records']);
        }

        return [
            'success' => true,
            'records' => $results
        ];
    }
    catch (Exception $e) {
        error_log("[Airtable POST] " . $e->getMessage());
        return [
            'success' => false,
            'error'   => $e->getMessage()
        ]; // return empty array on failure
    }
}

	//Helper for patching records to Airtable
	public function patchAirtableRecords($tableId, $records) {
        $apiUrl = "https://api.airtable.com/v0/{$this->baseId}/{$tableId}";
        $results = [];
        try {
            // Airtable accepts max 10 records per request
            $chunks = array_chunk($records, 10);
            foreach ($chunks as $chunk) {
                $recordsWrapped = array_map(function($rec) {
                    if (!isset($rec['id'])) {
                        throw new Exception("Each record must have an 'id' field for PATCH requests.");
                    }
                    // if 'fields' already exists, leave it
                    return isset($rec['fields']) ? $rec : ['id' => $rec['id'], 'fields' => $rec];
                }, $chunk);
                $payload = json_encode(['records' => $recordsWrapped]);
                $ch = curl_init($apiUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "Authorization: Bearer $this->apiKey",
                    "Content-Type: application/json"
                ]);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PATCH");
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                $responseRaw = curl_exec($ch);
                if ($responseRaw === false) {
                    $error = curl_error($ch);
                    curl_close($ch);
                    throw new Exception("cURL error: $error");
                }
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($httpCode !== 200) {
                    throw new Exception("Airtable API returned HTTP status $httpCode with body: $responseRaw");
                }
                $response = json_decode($responseRaw, true);
                if (!isset($response['records'])) {
                    throw new Exception("Invalid response structure: " . print_r($response, true));
                }
                $results = array_merge($results, $response['records']);
            }
            return [
                'success' => true,
                'records' => $results
            ];
        }
        catch (Exception $e) {
            error_log("[Airtable PATCH] " . $e->getMessage());
            return [
                'success' => false,
                'error'   => $e->getMessage()
            ];
        }
    }

	public function deleteAirtableRecords($tableId, $recordIds) {
        $results = [];
        try {
            // Airtable accepts max 10 records per delete request
            $chunks = array_chunk($recordIds, 10);
            foreach ($chunks as $chunk) {
                $queryString = implode('&', array_map(function($id) { return 'records[]=' . urlencode($id); }, $chunk));
                $apiUrl = "https://api.airtable.com/v0/{$this->baseId}/{$tableId}?{$queryString}";
                $ch = curl_init($apiUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "Authorization: Bearer {$this->apiKey}",
                    "Content-Type: application/json"
                ]);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                $responseRaw = curl_exec($ch);
                if ($responseRaw === false) {
                    $error = curl_error($ch);
                    curl_close($ch);
                    throw new Exception("cURL error: $error");
                }
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($httpCode !== 200) {
                    throw new Exception("Airtable API returned HTTP status $httpCode with body: $responseRaw");
                }
                $response = json_decode($responseRaw, true);
                if (!isset($response['records'])) {
                    throw new Exception("Invalid response structure: " . print_r($response, true));
                }
                $results = array_merge($results, $response['records']);
            }
            return [
                'success' => true,
                'records' => $results
            ];
        }
        catch (Exception $e) {
            error_log("[Airtable DELETE] " . $e->getMessage());
            return [
                'success' => false,
                'error'   => $e->getMessage()
            ];
        }
    }
	//Helper for Uploading Files to Airtable
	function uploadAttachmentToAirtable($recordId, $fieldIdOrName,$item) {
    	// Expecting $item from JS with keys: filename, contentType, base64
    	if (empty($item['base64'])) {
        	return [
            	'success' => false,
            	'error'   => "Missing base64 file content"
        	];
    	}

    	$filename    = !empty($item['filename'])    ? $item['filename']    : "upload.bin";
    	$contentType = !empty($item['contentType']) ? $item['contentType'] : "application/octet-stream";
    	$base64File  = $item['base64'];

    	$url = "https://content.airtable.com/v0/{$this->baseId}/{$recordId}/{$fieldIdOrName}/uploadAttachment";

    	$payload = json_encode([
        	'filename'    => $filename,
        	'contentType' => $contentType,
        	'file'        => $base64File
    	]);

    	$ch = curl_init($url);
    	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    	curl_setopt($ch, CURLOPT_POST, true);
    	curl_setopt($ch, CURLOPT_HTTPHEADER, [
        	"Authorization: Bearer $this->apiKey",
        	"Content-Type: application/json"
    	]);
    	curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

    	$responseRaw = curl_exec($ch);

    	if ($responseRaw === false) {
        	$error = curl_error($ch);
        	curl_close($ch);
        	return [
            	'success' => false,
            	'error'   => "cURL error: $error"
        	];
    	}

    	$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    	curl_close($ch);

    	$response = json_decode($responseRaw, true);

    	if ($httpCode < 200 || $httpCode >= 300) {
        	return [
            	'success' => false,
            	'error'   => "Airtable API returned HTTP status $httpCode",
            	'details' => $responseRaw
        	];
    	}

    	return [
        	'success'  => true,
        	'response' => $response
    	];
	}
	
    function getAirtableFieldPossibleValues($tableIdOrName, $fieldName) {
        try {
            // Get table schema to find field metadata
            $schemaUrl = "https://api.airtable.com/v0/meta/bases/{$this->baseId}/tables";
        
            $ch = curl_init($schemaUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer {$this->apiKey}",
                "Content-Type: application/json"
            ]);
        
            $responseRaw = curl_exec($ch);
            if ($responseRaw === false) {
                $error = curl_error($ch);
                curl_close($ch);
                throw new Exception("cURL error: $error");
            }
        
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        
            if ($httpCode !== 200) {
                throw new Exception("Airtable API returned HTTP status $httpCode with body: $responseRaw");
            }
        
            $response = json_decode($responseRaw, true);
        
            if (!isset($response['tables'])) {
                throw new Exception("Invalid response structure: " . print_r($response, true));
            }
        
            // Find the target table
            $targetTable = null;
            foreach ($response['tables'] as $table) {
                if ($table['id'] === $tableIdOrName || $table['name'] === $tableIdOrName) {
                    $targetTable = $table;
                    break;
                }
            }
        
            if (!$targetTable) {
                throw new Exception("Table '{$tableIdOrName}' not found");
            }
        
            // Find the target field
            $targetField = null;
            foreach ($targetTable['fields'] as $field) {
                if ($field['name'] === $fieldName) {
                    $targetField = $field;
                    break;
                }
            }
        
            if (!$targetField) {
                throw new Exception("Field '{$fieldName}' not found in table");
            }
        
            // Extract possible values based on field type
            $possibleValues = [];
        
            switch ($targetField['type']) {
                case 'singleSelect':
                case 'multipleSelects':
                    if (isset($targetField['options']['choices'])) {
                        foreach ($targetField['options']['choices'] as $choice) {
                            $possibleValues[] = [
                                'id' => $choice['id'] ?? null,
                                'name' => $choice['name'],
                                'color' => $choice['color'] ?? null
                            ];
                        }
                    }
                    break;
                
                case 'singleCollaborator':
                case 'multipleCollaborators':
                    throw new Exception('Collaborator fields require fetching from records. Use get_airtable_unique_values() instead.');
                
                case 'checkbox':
                    $possibleValues = [
                        ['name' => 'true', 'value' => true],
                        ['name' => 'false', 'value' => false]
                    ];
                    break;
                
                default:
                    throw new Exception("Field type '{$targetField['type']}' does not have predefined possible values. Consider using get_airtable_unique_values() to fetch unique values from records.");
            }
        
            return [
                'success' => true,
                'field_name' => $fieldName,
                'field_type' => $targetField['type'],
                'possible_values' => $possibleValues
            ];
        
        } catch (Exception $e) {
            error_log("[Airtable] " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    function getAirtableFieldsByType($tableName, $fieldType) {
        try {
            $url = "https://api.airtable.com/v0/meta/bases/{$this->baseId}/tables";

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer {$this->apiKey}",
                "Content-Type: application/json"
            ]);

            $responseRaw = curl_exec($ch);

            if ($responseRaw === false) {
                $error = curl_error($ch);
                curl_close($ch);
                throw new Exception("cURL error: $error");
            }

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                throw new Exception("Airtable API returned HTTP status $httpCode with body: $responseRaw");
            }

            $data = json_decode($responseRaw, true);

            if (!isset($data['tables'])) {
                throw new Exception("Invalid response structure: " . print_r($data, true));
            }

            $result = [];

            foreach ($data['tables'] as $table) {
                if ($table['name'] === $tableName) {
                    foreach ($table['fields'] as $field) {
                        if ($field['type'] === $fieldType) {
                            $result[] = [
                                'field'   => $field['name'],
                                'options' => $field['options'] ?? null
                            ];
                        }
                    }
                }
            }

            return [
                'success' => true,
                'result'  => $result
            ];

        } catch (Exception $e) {
            error_log("[Airtable] " . $e->getMessage());

            return [
                'success' => false,
                'error'   => $e->getMessage()
            ];
        }
    }
}

//-------------------------------------------------------------------------
//                             HELPERS
//-------------------------------------------------------------------------

class AirtableHelper {
	
	// $maxRecords is optional: null (default) keeps the existing behavior of fetching every matching record
	function fetchRecords (array $airtableParams = [], $filter = null, $fields = null, $returnFieldsByFieldId = false, $maxRecords = null){
		$BASE_ID = $airtableParams['baseId'] ?? null;
    	$TABLE_ID = $airtableParams['tableId'] ?? null;

		if(empty($BASE_ID) || empty($TABLE_ID)){
			return [
            	'success' => false,
            	'message' => 'missing airtable params!',
				'status' => 400,
        	];
		}
		$airtable = new AirtableService($BASE_ID);
		$fetchResult = $airtable->fetchAirtableRecords($TABLE_ID,$filter,$fields,$returnFieldsByFieldId,$maxRecords);
		
		if (!$fetchResult['success']) {
    		return [
            	'success' => false,
            	'message' => 'Data Fetch Failed!',
            	'error'   => $fetchResult['error'], // show the real reason
				//'status'  => 500,
        	];
		}
		else{
			return [
            	'success' => true,
            	'message' => 'Data Fetch Success!',
				'result' => $fetchResult['records'],
				'status'  => 200,
        	];
		}
	}
	function uploadRecords($payload,array $airtableParams = []){
		$BASE_ID = $airtableParams['baseId'] ?? null;
    	$TABLE_ID = $airtableParams['tableId'] ?? null;
		
		if(empty($BASE_ID) || empty($TABLE_ID)){
			return [
            	'success' => false,
            	'message' => 'missing airtable params!',
				'status' => 400,
        	];
		}

		$airtable = new AirtableService($BASE_ID);
		$uploadResult = $airtable->createAirtableRecords($TABLE_ID,$payload);
	
		if (!$uploadResult['success']) {
    		return [
            	'success' => false,
            	'message' => 'Upload Failed!',
            	'error'   => $uploadResult['error'] ?? 'Unknown error',
            	//'status'  => 500 // optional, can pass the HTTP code too
        	];
		}
		else{
			return [
        		'success' => true,
        		'message' => 'Upload success!',
        		'result'  => $uploadResult['records'] ?? [],
        		'status'  => 200 // optional
    		];
		}
	}
	function deleteRecords($payload,array $airtableParams = []){
	    $BASE_ID = $airtableParams['baseId'] ?? null;
    	$TABLE_ID = $airtableParams['tableId'] ?? null;
		
		if(empty($BASE_ID) || empty($TABLE_ID)){
			return [
            	'success' => false,
            	'message' => 'missing airtable params!',
				'status' => 400,
        	];
		}

		$airtable = new AirtableService($BASE_ID);
		$deleteResult = $airtable->deleteAirtableRecords($TABLE_ID,$payload);
	
		if (!$deleteResult['success']) {
    		return [
            	'success' => false,
            	'message' => 'Delete Failed!',
            	'error'   => $deleteResult['error'] ?? 'Unknown error',
            	//'status'  => 500 // optional, can pass the HTTP code too
        	];
		}
		else{
			return [
        		'success' => true,
        		'message' => 'Delete success!',
        		'result'  => $deleteResult['records'] ?? [],
        		'status'  => 200 // optional
    		];
		}
	}
	function patchRecords($payload, array $airtableParams = []){
        $BASE_ID = $airtableParams['baseId'] ?? null;
        $TABLE_ID = $airtableParams['tableId'] ?? null;

        if(empty($BASE_ID) || empty($TABLE_ID)){
            return [
                'success' => false,
                'message' => 'missing airtable params!',
                'status' => 400,
            ];
        }

        $airtable = new AirtableService($BASE_ID);
        $patchResult = $airtable->patchAirtableRecords($TABLE_ID, $payload);

        if(!$patchResult['success']){
            return [
                'success' => false,
                'message' => 'Patch Failed!',
                'error'   => $patchResult['error'] ?? 'Unknown error',
            ];
        }
        else{
            return [
                'success' => true,
                'message' => 'Patch success!',
                'result'  => $patchResult['records'] ?? [],
                'status'  => 200
            ];
        }
    }
	function uploadAttachments( $recordId, $payload, array $airtableParams = []){
		$BASE_ID = $airtableParams['baseId'] ?? null;
		$documents = [];

		if(empty($BASE_ID)){
			return [
            	'success' => false,
            	'message' => 'missing airtable params!',
				'status' => 400,
        	];
		}

		$airtable = new AirtableService($BASE_ID);
		
    	// Check if it has the "fields" wrapper
    	$fields = isset($payload['fields']) ? $payload['fields'] : $payload;
        // return if no files are received for uploading
        if(empty($fields)){
            return [
                'success' => false,
                'message' => 'No Files Uploaded!',
                'error'  => 'No Files Included in Request!',
			    'status' => 400,
            ];
        }
        
    	// Loop through fields
    	foreach ($fields as $fieldName => $files) {
        	foreach ($files as $fileObj) {
            	// Ensure the JS object keys exist
            	$filename    = isset($fileObj['filename']) ? $fileObj['filename'] : null;
            	$contentType = isset($fileObj['contentType']) ? $fileObj['contentType'] : null;
            	$base64      = isset($fileObj['base64Content']) ? $fileObj['base64Content'] : null;

            	if ($base64) { // only process if valid
                	$documents[] = [
                    	'fieldName'   => $fieldName,
                    	'filename'    => $filename,
                    	'contentType' => $contentType,
                    	'base64'      => $base64
                	];
            	}
        	}
    	}

    	$uploadResultHistory = [];

    	// Upload each document
    	foreach ($documents as $item) {
        	$fieldIdOrName = $item['fieldName'];
		
			$uploadResult = $airtable->uploadAttachmentToAirtable(
            	$recordId,
            	$fieldIdOrName,
            	$item
        	);

        	if (!$uploadResult['success']) {
            	return [
                    'success' => false,
                    'message' => 'Upload Failed!',
                    'error'   => $uploadResult['error'],
					'status' => 500,
                ];
        	}

        	$uploadResultHistory[] = [
            	'success'    => true,
            	'fieldName'  => $fieldIdOrName,
            	'filename'   => $item['filename'],
            	'contentType'=> $item['contentType']
        	];
    	}

    	return [
            'success' => true,
            'message' => 'upload success!',
            'result'  => $uploadResultHistory,
			'status' => 200,
        ];
	}
	function getFieldPossibleValues(array $airtableParams = []){
	    $BASE_ID = $airtableParams['baseId'] ?? null;
    	$TABLE_ID = $airtableParams['tableId'] ?? null;
    	$FIELD_NAME = $airtableParams['fieldName'] ?? null;
		
		if(empty($BASE_ID) || empty($TABLE_ID) || empty($FIELD_NAME)){
			return [
            	'success' => false,
            	'message' => 'missing airtable params!',
				'status' => 400,
        	];
		}
		$airtable = new AirtableService($BASE_ID);
		$fetchResult = $airtable->getAirtableFieldPossibleValues($TABLE_ID,$FIELD_NAME);
		
		if (!$fetchResult['success']) {
    		return [
            	'success' => false,
            	'message' => 'Data Fetch Failed!',
            	'error'   => $fetchResult['error'], // show the real reason
				//'status'  => 500,
        	];
		}
		else{
			return [
            	'success' => true,
            	'message' => 'Data Fetch Success!',
				'fieldName' => $fetchResult['field_name'],
				'fieldType' => $fetchResult['field_type'],
				'possibleValues' => $fetchResult['possible_values'],
				'status'  => 200,
        	];
		}
	}
	function getFieldsWithSpecificFieldType($tableName, $fieldType,array $airtableParams = []){
	    $BASE_ID = $airtableParams['baseId'] ?? null;
    	$TABLE_ID = $airtableParams['tableId'] ?? null;
    	$FIELD_NAME = $airtableParams['fieldName'] ?? null;
		
		if(empty($BASE_ID)){
			return [
            	'success' => false,
            	'message' => 'missing airtable params!',
				'status' => 400,
        	];
		}
		$airtable = new AirtableService($BASE_ID);
		$fetchResult = $airtable->getAirtableFieldsByType($tableName,$fieldType);

		if (!$fetchResult['success']) {
    		return [
            	'success' => false,
            	'message' => 'Data Fetch Failed!',
            	'error'   => $fetchResult['error'], // show the real reason
				//'status'  => 500,
        	];
		}
		else{
			return [
            	'success' => true,
            	'message' => 'Data Fetch Success!',
            	'result' => $fetchResult,
				'status'  => 200,
        	];
		}
	}
}

//-------------------------------------------------------------------------
//                             EMAIL
//-------------------------------------------------------------------------

// Outbound mail for the project estimator, sent through wp_mail (WordPress is already loaded here, so
// unlike the standalone cron scripts this needs no wp-load.php). From/Reply-To are supplied by the
// route's config globals and are never client-settable, and every client-supplied value that reaches
// the template is escaped in here - so a caller cannot forget to escape it at the call site.
class MailService {

	// Builds the estimate-share message. $note is the sender's own plain-text message; $files lists the
	// attached filenames so the recipient can see what should be there.
	function renderEstimateShare($estimateName, $note, $senderName, array $files = []) {
		$displayName = $estimateName !== '' ? $estimateName : 'Untitled estimate';
		$displaySender = $senderName !== '' ? $senderName : 'An IKAIKABIM user';
		$noteHtml = $note !== '' ? '<p style="margin:0 0 16px 0;">' . nl2br(esc_html($note)) . '</p>' : '';

		$fileListHtml = '';
		if (!empty($files)) {
			$items = '';
			foreach ($files as $filename) {
				$items .= '<li>' . esc_html($filename) . '</li>';
			}
			$fileListHtml = '<p style="margin:0 0 8px 0;"><strong>Attached files</strong></p>'
				. '<ul style="margin:0 0 16px 0;padding-left:20px;">' . $items . '</ul>';
		}

		$html = '<!DOCTYPE html><html><body style="margin:0;padding:24px;background-color:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">'
			. '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;margin:0 auto;background-color:#ffffff;border-radius:6px;">'
			. '<tr><td style="padding:24px;">'
			. '<h1 style="margin:0 0 4px 0;font-size:20px;">Project Estimate</h1>'
			. '<p style="margin:0 0 20px 0;font-size:14px;color:#6b7280;">' . esc_html($displaySender) . ' shared an estimate with you.</p>'
			. '<p style="margin:0 0 16px 0;font-size:16px;"><strong>' . esc_html($displayName) . '</strong></p>'
			. $noteHtml
			. $fileListHtml
			. '</td></tr>'
			. '<tr><td style="padding:16px 24px;background-color:#f8fafc;border-top:1px solid #e2e8f0;font-size:12px;color:#9ca3af;">'
			. 'Sent automatically by the IKAIKABIM Project Estimator.'
			. '</td></tr></table></body></html>';

		// plain-text alternative: an HTML-only message scores worse with spam filters and degrades badly
		// in text-only clients, so every message carries both parts
		$text = $displaySender . " shared an estimate with you.\n\n" . $displayName . "\n\n";
		if ($note !== '') {
			$text .= $note . "\n\n";
		}
		if (!empty($files)) {
			$text .= "Attached files:\n- " . implode("\n- ", $files) . "\n\n";
		}
		$text .= 'Sent automatically by the IKAIKABIM Project Estimator.';

		return ['html' => $html, 'text' => $text];
	}

	// Sends one message. $body is ['html' => ..., 'text' => ...]; $config is ['from' => ..., 'replyTo' => ...];
	// $attachments is [['filename' => ..., 'type' => ..., 'bytes' => ...], ...].
	// Returns ['ok' => bool, 'error' => string|null] - wp_mail only hands back a bool, so the real reason
	// is captured off the wp_mail_failed hook instead of being discarded.
	function send($to, $subject, array $body, array $config, array $attachments = []) {
		$headers = [
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $config['from'],
		];
		// noreply@ cannot receive replies, so without this a recipient hitting Reply just gets a bounce
		if (!empty($config['replyTo'])) {
			$headers[] = 'Reply-To: ' . $config['replyTo'];
		}

		// wp_mail's $attachments argument takes file PATHS, but these bytes come straight from Airtable -
		// hooking phpmailer_init attaches them in memory rather than writing temp files on shared hosting.
		// The same hook carries the plain-text part, which wp_mail has no argument for.
		$initHook = function ($phpmailer) use ($body, $attachments) {
			$phpmailer->AltBody = $body['text'];
			foreach ($attachments as $file) {
				$phpmailer->addStringAttachment(
					$file['bytes'],
					$file['filename'],
					'base64',
					$file['type'] ?? 'application/octet-stream'
				);
			}
		};
		$error = null;
		$failHook = function ($wpError) use (&$error) {
			$error = $wpError->get_error_message();
		};

		add_action('phpmailer_init', $initHook);
		add_action('wp_mail_failed', $failHook);
		$sent = wp_mail($to, $subject, $body['html'], $headers);
		// both hooks are removed straight away so they never touch any other mail this request sends
		remove_action('phpmailer_init', $initHook);
		remove_action('wp_mail_failed', $failHook);

		return [
			'ok' => (bool) $sent,
			'error' => $sent ? null : ($error ?: 'wp_mail returned false without reporting a reason')
		];
	}
}