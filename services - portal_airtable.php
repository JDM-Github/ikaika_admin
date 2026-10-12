<?php
namespace portalikaikabim\services\airtableServices;
use Exception;

//-------------------------------------------------------------------
//                          SERVICES
//-------------------------------------------------------------------
class AirtableService {
    private $baseId;
	private $transactionTrackerBaseId;
    private $calendarBaseId;
    private $apiKey = AIRTABLE_API_KEY;

    public function __construct(string $baseId, array $config = []) {
    	$this->baseId = $baseId;
    	$this->transactionTrackerBaseId = $config['transactionTrackerBaseId'] ?? null;
    	$this->calendarBaseId = $config['calendarBaseId'] ?? null;
	}
	
	function fetchAirtableRecords($tableId,$filter = null) {
    	$records = [];
    	$offset = null;
		try{
			do {
        		$url = "https://api.airtable.com/v0/{$this->baseId}/{$tableId}";

				// Build query params
				$params = [];
				if (!empty($offset)) {
    				$params['offset'] = $offset;
				}
				if (!empty($filter)) {
    				$params['filterByFormula'] = $filter; // example filter
				}
				// If we have params, append them properly
				if (!empty($params)) {
    				$url .= '?' . http_build_query($params);
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
	
	function fetchRecords (array $airtableParams = [], $filter = null){
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
		$fetchResult = $airtable->fetchAirtableRecords($TABLE_ID,$filter);
		
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