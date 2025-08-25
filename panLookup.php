<?php
// pan_lookup.php
header('Content-Type: application/json');

function fail($code, $msg) {
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_SLASHES);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  fail(405, 'Use POST');
}

$pan = $_POST['pan'] ?? '';
$pan = trim($pan);

// Server-side validation: exactly 16 digits
if (!preg_match('/^\d{16}$/', $pan)) {
  fail(400, 'PAN must be exactly 16 digits.');
}

// Build the SQL command (this mirrors your legacy call)
// NOTE: Since we can’t parameterize the legacy endpoint, we strictly validate PAN above.
$sql = "exec VAS_ABT_PAN_Lookup '{$pan}'";

// SOAP client options
$wsdl = "http://10.8.138.11/SQL/DALSQL.asmx?wsdl";
$options = [
  'trace' => 0,
  'exceptions' => true,
  'cache_wsdl' => WSDL_CACHE_MEMORY,
  'connection_timeout' => 10,  // seconds to connect
  'stream_context' => stream_context_create([
    'http' => [
      'timeout' => 20,        // seconds to read
      'header'  => "Connection: close\r\n",
    ],
  ]),
];

try {
  $client = new SoapClient($wsdl, $options);

  // Call the ASMX "Open" method with parameter "SQLCommand"
  // The response type is typically base64Binary; PHP exposes it as a string.
  $resp = $client->Open(['SQLCommand' => $sql]);
  // Common property holding the payload:
  $raw = $resp->OpenResult ?? '';

  if (!is_string($raw) || $raw === '') {
    fail(502, 'Backend returned no data.');
  }

  // The payload is often XML bytes (sometimes base64). Try both paths.
  $xmlStr = $raw;

  // If the string doesn't look like XML, try base64-decoding it.
  if (strpos($xmlStr, '<') === false) {
    $decoded = @base64_decode($xmlStr, true);
    if ($decoded !== false && strpos($decoded, '<') !== false) {
      $xmlStr = $decoded;
    }
  }

  if (strpos($xmlStr, '<') === false) {
    fail(502, 'Backend returned non-XML payload.');
  }

  // --- Safe XML load ---
  // Avoid external entity loads and noisy warnings.
  $dom = new DOMDocument();
  $ok = @$dom->loadXML($xmlStr, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
  if (!$ok) {
    fail(502, 'Invalid XML from backend.');
  }
  $sx = simplexml_import_dom($dom);
  if ($sx === false) {
    fail(502, 'Failed to parse XML.');
  }

  // Helper: convert all rows under a tag name (<Table>, <Table1>, …) into arrays
  $extractRows = function (SimpleXMLElement $root, string $tag): array {
    $rows = [];
    foreach ($root->xpath("//{$tag}") as $row) {
      $item = [];
      foreach ($row->children() as $child) {
        $item[$child->getName()] = (string)$child;
      }
      $rows[] = $item;
    }
    return $rows;
  };

  // Pull all expected tables (adjust if your backend uses different tag names)
  $tables = [
    'Table'  => $extractRows($sx, 'Table'),   // TravelCardAccountData
    'Table1' => $extractRows($sx, 'Table1'),  // ValidationListHistoryCS
    'Table2' => $extractRows($sx, 'Table2'),  // VAS_TransitValidationList (usually 1 row)
    'Table3' => $extractRows($sx, 'Table3'),  // VAS_TransitValidationListHistory
    'Table4' => $extractRows($sx, 'Table4'),  // PaymentsAllocated
    'Table5' => $extractRows($sx, 'Table5'),  // TransactionsReceived
    'Table6' => $extractRows($sx, 'Table6'),  // PrepaidBalanceHistory
  ];

  // Build a compact summary for your UI
  $summary = [];
  foreach ($tables as $k => $rows) { $summary[$k] = count($rows); }

  // Pick a small "sample" to display (first row from any populated table)
  $sample = [];
  foreach (['Table','Table4','Table5','Table6','Table1','Table3','Table2'] as $k) {
    if (!empty($tables[$k])) { $sample = $tables[$k][0]; break; }
  }

  // Respond with JSON
  echo json_encode([
    'ok' => true,
    'pan' => $pan,
    'queried_at' => date('c'),
    'summary' => $summary,
    'tables' => $tables,    // Full data available to your front-end if you want to render it
    'sample' => $sample     // Quick preview
  ], JSON_UNESCAPED_SLASHES);

} catch (SoapFault $e) {
  fail(502, 'SOAP error: ' . $e->getMessage());
} catch (Throwable $e) {
  fail(500, 'Server error: ' . $e->getMessage());
}
