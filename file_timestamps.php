<?php
date_default_timezone_set('Australia/Sydney');
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- BOM FTP Info ---
$FTP_HOST = 'ftp.bom.gov.au';
$FTP_USER = 'anonymous';
$FTP_PASS = '';
$FTP_BASE = '/anon/gen/fwo/';

// --- State Mapping ---
$STATE_MAP = [
    'NSW' => 'IDN60910',
    'VIC' => 'IDV60910',
    'QLD' => 'IDQ60910',
    'WA'  => 'IDW60910',
    'SA'  => 'IDS60910',
    'TAS' => 'IDT60910',
    'NT'  => 'IDD60910'
];

// --- Connect to FTP ---
$ftpConn = ftp_connect($FTP_HOST, 21, 10);
if (!$ftpConn) {
    die("Could not connect to FTP server $FTP_HOST");
}
if (!ftp_login($ftpConn, $FTP_USER, $FTP_PASS)) {
    ftp_close($ftpConn);
    die("Could not login to FTP server with anonymous");
}

// --- Fetch and display modification times ---
echo "<h2>BOM TGZ File Modification Times</h2>";
echo "<table border='1' cellpadding='5' cellspacing='0'>";
echo "<tr><th>State</th><th>Product ID</th><th>Filename</th><th>Remote Modification Time</th></tr>";

foreach ($STATE_MAP as $state => $productId) {
    $filename = $productId . '.tgz';
    
    // Get modification time
    $mtime = ftp_mdtm($ftpConn, $FTP_BASE . $filename);
    
    if ($mtime != -1) {
        $human = date('Y-m-d H:i:s', $mtime);
    } else {
        $human = 'UNKNOWN';
    }
    
    echo "<tr>
        <td>{$state}</td>
        <td>{$productId}</td>
        <td>{$filename}</td>
        <td>{$human}</td>
    </tr>";
}

echo "</table>";
ftp_close($ftpConn);

?>
