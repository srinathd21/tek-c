<?php
// qs-manage-quotation.php – QS manages a single quotation request: view details, add/edit/delete quotations, manage items, finalize.

session_start();
require_once 'includes/db-config.php';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

$success = '';
$error = '';
$request_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Auth: QS only
if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}
$empId = (int)$_SESSION['employee_id'];
$designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));
$department = strtolower(trim((string)($_SESSION['department'] ?? '')));
$is_qs = ($department === 'qs' || strpos($designation, 'qs') !== false);
if (!$is_qs) {
    header("Location: index.php");
    exit;
}
if ($request_id <= 0) {
    header("Location: qs-quotations.php");
    exit;
}

// Fetch the request, ensure it's assigned to this QS
$req_query = "
    SELECT qr.*, s.project_name, s.project_code, s.manager_employee_id, s.team_lead_employee_id,
           c.client_name, c.company_name,
           m.full_name AS manager_name, tl.full_name AS team_lead_name,
           pe.full_name AS project_engineer_name,
           rb.full_name AS requested_by_name
    FROM quotation_requests qr
    JOIN sites s ON qr.site_id = s.id
    LEFT JOIN clients c ON s.client_id = c.id
    LEFT JOIN employees m ON s.manager_employee_id = m.id
    LEFT JOIN employees tl ON s.team_lead_employee_id = tl.id
    LEFT JOIN employees pe ON qr.project_engineer_id = pe.id
    LEFT JOIN employees rb ON qr.requested_by = rb.id
    WHERE qr.id = ? AND qr.qs_employee_id = ?
";
$stmt = mysqli_prepare($conn, $req_query);
mysqli_stmt_bind_param($stmt, "ii", $request_id, $empId);
mysqli_stmt_execute($stmt);
$req_res = mysqli_stmt_get_result($stmt);
$request = mysqli_fetch_assoc($req_res);
mysqli_stmt_close($stmt);
if (!$request) {
    header("Location: qs-quotations.php");
    exit;
}

function tableExists($conn, string $table): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $res = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'");
    if (!$res) return false;
    $ok = mysqli_num_rows($res) > 0;
    mysqli_free_result($res);
    return $ok;
}

function columnExists($conn, string $table, string $column): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $col = mysqli_real_escape_string($conn, $column);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$col'");
    if (!$res) return false;
    $ok = mysqli_num_rows($res) > 0;
    mysqli_free_result($res);
    return $ok;
}

function createNotificationCurrentDb(
    $conn,
    int $employeeId,
    string $title,
    string $message,
    string $module,
    int $referenceId,
    string $link,
    string $type = 'quotation'
): bool {
    if ($employeeId <= 0 || !$conn || !tableExists($conn, 'notifications')) {
        return false;
    }

    $columns = [];
    $placeholders = [];
    $types = '';
    $values = [];

    $map = [
        'employee_id'  => ['i', $employeeId],
        'title'        => ['s', $title],
        'message'      => ['s', $message],
        'type'         => ['s', $type],
        'module'       => ['s', $module],
        'reference_id' => ['i', $referenceId],
        'link'         => ['s', $link],
        'priority'     => ['s', 'normal'],
        'is_read'      => ['i', 0],
        'created_at'   => ['raw', 'NOW()'],
    ];

    foreach ($map as $column => $pair) {
        if (columnExists($conn, 'notifications', $column)) {
            $columns[] = "`$column`";

            if ($pair[0] === 'raw') {
                $placeholders[] = $pair[1];
            } else {
                $placeholders[] = '?';
                $types .= $pair[0];
                $values[] = $pair[1];
            }
        }
    }

    if (!$columns) return false;

    $sql = "INSERT INTO notifications (" . implode(',', $columns) . ") VALUES (" . implode(',', $placeholders) . ")";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return false;

    if ($values) {
        mysqli_stmt_bind_param($stmt, $types, ...$values);
    }

    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

function logQsQuotationActivity($conn, int $actorId, string $activityType, string $description, int $referenceId, array $newData = []): bool {
    if (!$conn || !tableExists($conn, 'activity_logs')) return false;

    $newJson = $newData ? json_encode($newData, JSON_UNESCAPED_UNICODE) : null;
    $employeeName = $_SESSION['employee_name'] ?? $_SESSION['username'] ?? 'System';
    $username = $_SESSION['username'] ?? '';
    $designation = $_SESSION['designation'] ?? '';
    $department = $_SESSION['department'] ?? '';
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

    $map = [
        'employee_id'   => ['i', $actorId],
        'employee_name' => ['s', $employeeName],
        'username'      => ['s', $username],
        'designation'   => ['s', $designation],
        'department'    => ['s', $department],
        'activity_type' => ['s', $activityType],
        'module'        => ['s', 'quotation_requests'],
        'description'   => ['s', $description],
        'reference_id'  => ['i', $referenceId],
        'new_data'      => ['s', $newJson],
        'ip_address'    => ['s', $ipAddress],
    ];

    $cols = [];
    $types = '';
    $values = [];

    foreach ($map as $column => $pair) {
        if (columnExists($conn, 'activity_logs', $column)) {
            $cols[] = "`$column`";
            $types .= $pair[0];
            $values[] = $pair[1];
        }
    }

    if (!$cols) return false;

    $sql = "INSERT INTO activity_logs (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return false;

    mysqli_stmt_bind_param($stmt, $types, ...$values);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

function normalizeDocumentPath($path): string {
    $path = trim(str_replace('\\', '/', (string)$path));

    if ($path === '') {
        return '';
    }

    // Keep full URLs as-is.
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    // Remove accidental local server absolute prefixes if stored.
    $path = preg_replace('#^C:/wamp64/www/git/tek-c/erp/#i', '', $path);
    $path = ltrim($path, '/');

    return $path;
}

function buildDirectProjectEngineerDbUrl($dbValue): string {
    $dbValue = normalizeDocumentPath($dbValue);

    if ($dbValue === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $dbValue)) {
        return $dbValue;
    }

    // Avoid double prefix.
    if (str_starts_with($dbValue, '../project-engineer/')) {
        return $dbValue;
    }

    if (str_starts_with($dbValue, 'project-engineer/')) {
        return '../' . $dbValue;
    }

    // If DB value starts as ../uploads/..., remove ../ then prefix project-engineer.
    if (str_starts_with($dbValue, '../uploads/')) {
        $dbValue = substr($dbValue, 3);
    }

    return '../project-engineer/' . ltrim($dbValue, '/');
}

function buildProjectEngineerDocumentUrl($path): string {
    $path = normalizeDocumentPath($path);

    if ($path === '') {
        return '';
    }

    // Keep full URLs as-is.
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    // If already relative to parent or already points to project-engineer, do not double-prefix.
    if (str_starts_with($path, '../project-engineer/')) {
        return $path;
    }

    if (str_starts_with($path, 'project-engineer/')) {
        return '../' . $path;
    }

    // If admin path is used, keep it as-is.
    if (str_starts_with($path, '../admin/') || str_starts_with($path, 'admin/')) {
        return $path;
    }

    // If path was saved as ../uploads/quotation_requests/... from project-engineer,
    // convert it to the correct sibling panel path.
    if (
        str_starts_with($path, '../uploads/quotation_requests/') ||
        str_starts_with($path, '../uploads/quotation_request/') ||
        str_starts_with($path, '../uploads/requests/') ||
        str_starts_with($path, '../uploads/drawings/')
    ) {
        return '../project-engineer/' . ltrim(substr($path, 3), '/');
    }

    // Request documents are uploaded from project-engineer panel.
    if (
        str_starts_with($path, 'uploads/quotation_requests/') ||
        str_starts_with($path, 'uploads/quotation_request/') ||
        str_starts_with($path, 'uploads/requests/') ||
        str_starts_with($path, 'uploads/drawings/')
    ) {
        return '../project-engineer/' . $path;
    }

    // Fallback for request docs saved as only a file name.
    if (!str_contains($path, '/')) {
        return '../project-engineer/uploads/quotation_requests/documents/' . $path;
    }

    return $path;
}

function pickDocumentPathFromArray(array $doc): string {
    $pathKeys = [
        'file_path',
        'path',
        'url',
        'file_url',
        'document',
        'document_path',
        'document_file',
        'saved_path',
        'upload_path',
        'uploaded_path',
        'stored_path',
        'attachment_path',
        'attachment_file',
        'drawing_file',
        'drawing_path',
        'file'
    ];

    foreach ($pathKeys as $key) {
        if (!empty($doc[$key])) {
            return normalizeDocumentPath($doc[$key]);
        }
    }

    return '';
}

function pickDocumentNameFromArray(array $doc, string $path = ''): string {
    $nameKeys = [
        'file_name',
        'name',
        'original_name',
        'original_filename',
        'filename',
        'document_name',
        'title',
        'label'
    ];

    foreach ($nameKeys as $key) {
        if (!empty($doc[$key])) {
            return (string)$doc[$key];
        }
    }

    return $path !== '' ? basename($path) : 'Document';
}

function resolveDocumentPathByName(string $fileName): string {
    $fileName = trim($fileName);

    if ($fileName === '' || $fileName === 'Document') {
        return '';
    }

    $cleanName = basename($fileName);

    $possiblePaths = [
        '../project-engineer/uploads/quotation_requests/documents/' . $cleanName,
        '../project-engineer/uploads/quotation_requests/' . $cleanName,
        '../project-engineer/uploads/quotation_request/' . $cleanName,
        '../project-engineer/uploads/requests/' . $cleanName,
        '../project-engineer/uploads/drawings/' . $cleanName,
        'uploads/quotation_requests/documents/' . $cleanName,
        'uploads/quotation_requests/' . $cleanName,
        'uploads/quotations/' . $cleanName,
        '../uploads/quotations/' . $cleanName
    ];

    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            return buildProjectEngineerDocumentUrl($path);
        }
    }

    // If only file name is stored, assume project-engineer request document folder.
    return '../project-engineer/uploads/quotation_requests/documents/' . $cleanName;
}

function buildProjectEngineerFileUrl($dbValue): string {
    $dbValue = trim(str_replace('\\', '/', (string)$dbValue));

    if ($dbValue === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $dbValue)) {
        return $dbValue;
    }

    // Remove local absolute path if saved by mistake.
    $dbValue = preg_replace('#^C:/wamp64/www/git/tek-c/erp/#i', '', $dbValue);
    $dbValue = ltrim($dbValue, '/');

    if (str_starts_with($dbValue, '../project-engineer/')) {
        return $dbValue;
    }

    if (str_starts_with($dbValue, 'project-engineer/')) {
        return '../' . $dbValue;
    }

    if (str_starts_with($dbValue, '../uploads/')) {
        $dbValue = substr($dbValue, 3);
    }

    // Actual uploaded request files are inside project-engineer panel.
    return '../project-engineer/' . ltrim($dbValue, '/');
}

function getAdditionalDocumentsFromRequest(array $request): array {
    $docs = [];

    // 1) Drawing file from actual table column: quotation_requests.drawing_file
    if (!empty($request['drawing_file'])) {
        $path = (string)$request['drawing_file'];
        $docs[] = [
            'group'       => 'Drawing',
            'file_name'   => basename($path),
            'file_path'   => $path,
            'url'         => buildProjectEngineerFileUrl($path),
            'file_size'   => '',
            'uploaded_at' => ''
        ];
    }

    // 2) Additional documents from actual table column:
    // additional_documents_json = {"additional":[{"original_name":"...","file_path":"uploads/...","uploaded_at":"..."}]}
    if (!empty($request['additional_documents_json'])) {
        $decoded = json_decode((string)$request['additional_documents_json'], true);

        if (is_array($decoded)) {
            $items = [];

            if (isset($decoded['additional']) && is_array($decoded['additional'])) {
                $items = $decoded['additional'];
            } elseif (array_is_list($decoded)) {
                $items = $decoded;
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $path = (string)($item['file_path'] ?? $item['path'] ?? '');
                $name = (string)($item['original_name'] ?? $item['file_name'] ?? $item['name'] ?? basename($path));

                $docs[] = [
                    'group'       => 'Additional',
                    'file_name'   => $name ?: basename($path),
                    'file_path'   => $path,
                    'url'         => buildProjectEngineerFileUrl($path),
                    'file_size'   => $item['file_size'] ?? $item['size'] ?? '',
                    'uploaded_at' => $item['uploaded_at'] ?? $item['created_at'] ?? ''
                ];
            }
        }
    }

    // 3) Optional fallback for attachments_json if used later.
    if (!empty($request['attachments_json'])) {
        $decoded = json_decode((string)$request['attachments_json'], true);
        if (is_array($decoded)) {
            $items = isset($decoded['attachments']) && is_array($decoded['attachments']) ? $decoded['attachments'] : (array_is_list($decoded) ? $decoded : []);
            foreach ($items as $item) {
                if (!is_array($item)) continue;

                $path = (string)($item['file_path'] ?? $item['path'] ?? '');
                $name = (string)($item['original_name'] ?? $item['file_name'] ?? $item['name'] ?? basename($path));

                $docs[] = [
                    'group'       => 'Attachment',
                    'file_name'   => $name ?: basename($path),
                    'file_path'   => $path,
                    'url'         => buildProjectEngineerFileUrl($path),
                    'file_size'   => $item['file_size'] ?? $item['size'] ?? '',
                    'uploaded_at' => $item['uploaded_at'] ?? $item['created_at'] ?? ''
                ];
            }
        }
    }

    // Deduplicate by file_path/url.
    $unique = [];
    foreach ($docs as $doc) {
        $key = (string)($doc['file_path'] ?: $doc['url'] ?: $doc['file_name']);
        if ($key !== '' && !isset($unique[$key])) {
            $unique[$key] = $doc;
        }
    }

    return array_values($unique);
}


function buildQuotationDocumentUrl($path): string {
    $path = normalizeDocumentPath($path);

    if ($path === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    if (str_starts_with($path, '../')) {
        return $path;
    }

    if (str_starts_with($path, 'uploads/quotations/')) {
        return '../' . $path;
    }

    return $path;
}

function formatFileSizeDisplay($size): string {
    if ($size === '' || $size === null) return '';
    $bytes = (float)$size;
    if ($bytes <= 0) return '';
    if ($bytes >= 1024 * 1024) return number_format($bytes / (1024 * 1024), 1) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 1) . ' KB';
    return number_format($bytes) . ' B';
}

// Handle file upload for quotation document
function handleFileUpload($file, $quotation_id) {
    $target_dir = "../uploads/quotations/";
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    $file_extension = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
    $allowed_types = ['pdf', 'jpg', 'jpeg', 'png', 'xls', 'xlsx', 'doc', 'docx'];
    if (!in_array($file_extension, $allowed_types)) {
        return ["error" => "Invalid file type. Allowed: PDF, JPG, PNG, Excel, Word."];
    }
    if ($file["size"] > 5 * 1024 * 1024) { // 5 MB
        return ["error" => "File size too large. Max 5 MB."];
    }
    $new_filename = "quotation_" . $quotation_id . "_" . time() . "." . $file_extension;
    $target_file = $target_dir . $new_filename;
    if (move_uploaded_file($file["tmp_name"], $target_file)) {
        return ["success" => $target_file];
    } else {
        return ["error" => "Failed to upload file."];
    }
}

$additional_documents = getAdditionalDocumentsFromRequest($request);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];

    // Add quotation (manual or from dealer)
    if ($action === 'add_quotation') {
        $dealer_id = intval($_POST['dealer_id'] ?? 0);
        $total_amount = floatval($_POST['total_amount'] ?? 0);
        $delivery_terms = trim($_POST['delivery_terms'] ?? '');
        $payment_terms = trim($_POST['payment_terms'] ?? '');
        $warranty = trim($_POST['warranty'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');
        $quotation_document = null;

        if (isset($_FILES['quotation_document']) && $_FILES['quotation_document']['error'] === UPLOAD_ERR_OK) {
            $upload = handleFileUpload($_FILES['quotation_document'], 0); // placeholder
            if (isset($upload['error'])) {
                $error = $upload['error'];
                goto after_add;
            } else {
                $quotation_document = $upload['success'];
            }
        }

        if ($dealer_id <= 0 || $total_amount <= 0) {
            $error = "Please select a dealer and enter a valid total amount.";
        } else {
            // Verify dealer exists and is active
            $check_dealer = "SELECT dealer_name FROM quotation_dealers WHERE id = ? AND status = 'Active'";
            $stmt = mysqli_prepare($conn, $check_dealer);
            mysqli_stmt_bind_param($stmt, "i", $dealer_id);
            mysqli_stmt_execute($stmt);
            $dealer_res = mysqli_stmt_get_result($stmt);
            $dealer = mysqli_fetch_assoc($dealer_res);
            mysqli_stmt_close($stmt);
            if (!$dealer) {
                $error = "Invalid or inactive dealer selected.";
            } else {
                $quotation_no = 'QT-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                $insert = "INSERT INTO quotations (quotation_no, quotation_request_id, dealer_id, total_amount, grand_total, delivery_terms, payment_terms, warranty, remarks, quotation_document, submitted_by, submitted_by_name, submitted_at, status)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'With QS')";
                $stmt = mysqli_prepare($conn, $insert);
                mysqli_stmt_bind_param($stmt, "siidssssssis", $quotation_no, $request_id, $dealer_id, $total_amount, $total_amount, $delivery_terms, $payment_terms, $warranty, $remarks, $quotation_document, $empId, $_SESSION['employee_name']);
                if (mysqli_stmt_execute($stmt)) {
                    $quotation_id = mysqli_stmt_insert_id($stmt);
                    if ($quotation_document && strpos($quotation_document, "quotation_0_") !== false) {
                        $new_name = str_replace("quotation_0_", "quotation_" . $quotation_id . "_", $quotation_document);
                        rename($quotation_document, $new_name);
                        $update_doc = "UPDATE quotations SET quotation_document = ? WHERE id = ?";
                        $stmt2 = mysqli_prepare($conn, $update_doc);
                        mysqli_stmt_bind_param($stmt2, "si", $new_name, $quotation_id);
                        mysqli_stmt_execute($stmt2);
                        mysqli_stmt_close($stmt2);
                    }
                    $success = "Quotation added successfully.";
                } else {
                    $error = "Failed to add quotation: " . mysqli_error($conn);
                }
                mysqli_stmt_close($stmt);
            }
        }
        after_add:
    }

    // Edit quotation
    if ($action === 'edit_quotation') {
        $quotation_id = intval($_POST['quotation_id']);
        $dealer_id = intval($_POST['dealer_id']);
        $total_amount = floatval($_POST['total_amount']);
        $delivery_terms = trim($_POST['delivery_terms']);
        $payment_terms = trim($_POST['payment_terms']);
        $warranty = trim($_POST['warranty']);
        $remarks = trim($_POST['remarks']);
        $quotation_document = null;

        if (isset($_FILES['quotation_document']) && $_FILES['quotation_document']['error'] === UPLOAD_ERR_OK) {
            $upload = handleFileUpload($_FILES['quotation_document'], $quotation_id);
            if (isset($upload['error'])) {
                $error = $upload['error'];
                goto after_edit;
            } else {
                $quotation_document = $upload['success'];
            }
        }

        if ($dealer_id <= 0 || $total_amount <= 0) {
            $error = "Dealer and total amount are required.";
        } else {
            if ($quotation_document) {
                $update = "UPDATE quotations SET dealer_id = ?, total_amount = ?, grand_total = ?, delivery_terms = ?, payment_terms = ?, warranty = ?, remarks = ?, quotation_document = ? WHERE id = ? AND quotation_request_id = ?";
                $stmt = mysqli_prepare($conn, $update);
                mysqli_stmt_bind_param($stmt, "iddsssssii", $dealer_id, $total_amount, $total_amount, $delivery_terms, $payment_terms, $warranty, $remarks, $quotation_document, $quotation_id, $request_id);
            } else {
                $update = "UPDATE quotations SET dealer_id = ?, total_amount = ?, grand_total = ?, delivery_terms = ?, payment_terms = ?, warranty = ?, remarks = ? WHERE id = ? AND quotation_request_id = ?";
                $stmt = mysqli_prepare($conn, $update);
                mysqli_stmt_bind_param($stmt, "iddssssii", $dealer_id, $total_amount, $total_amount, $delivery_terms, $payment_terms, $warranty, $remarks, $quotation_id, $request_id);
            }
            if (mysqli_stmt_execute($stmt)) {
                $success = "Quotation updated.";
            } else {
                $error = "Failed to update.";
            }
            mysqli_stmt_close($stmt);
        }
        after_edit:
    }

    // Delete quotation
    if ($action === 'delete_quotation') {
        $quotation_id = intval($_POST['quotation_id']);
        $delete = "DELETE FROM quotations WHERE id = ? AND quotation_request_id = ?";
        $stmt = mysqli_prepare($conn, $delete);
        mysqli_stmt_bind_param($stmt, "ii", $quotation_id, $request_id);
        if (mysqli_stmt_execute($stmt)) {
            $success = "Quotation deleted.";
        } else {
            $error = "Failed to delete.";
        }
        mysqli_stmt_close($stmt);
    }

    // Add item to quotation
    if ($action === 'add_item') {
        $quotation_id = intval($_POST['quotation_id']);
        $item_name = trim($_POST['item_name']);
        $quantity = floatval($_POST['quantity']);
        $unit = trim($_POST['unit']);
        $unit_price = floatval($_POST['unit_price']);
        $description = trim($_POST['description']);
        if ($item_name == '' || $quantity <= 0 || $unit_price <= 0) {
            $error = "Item name, quantity, and unit price are required.";
        } else {
            $insert = "INSERT INTO quotation_items (quotation_id, item_name, description, quantity, unit, unit_price, discount_percentage, discount_amount, cgst_amount, sgst_amount, igst_amount)
                       VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, 0, 0)";
            $stmt = mysqli_prepare($conn, $insert);
            mysqli_stmt_bind_param($stmt, "issdds", $quotation_id, $item_name, $description, $quantity, $unit, $unit_price);
            if (mysqli_stmt_execute($stmt)) {
                // Recalculate total
                $sum_total = "SELECT SUM(quantity * unit_price) AS total FROM quotation_items WHERE quotation_id = ?";
                $stmt2 = mysqli_prepare($conn, $sum_total);
                mysqli_stmt_bind_param($stmt2, "i", $quotation_id);
                mysqli_stmt_execute($stmt2);
                $res2 = mysqli_stmt_get_result($stmt2);
                $row2 = mysqli_fetch_assoc($res2);
                $new_total = floatval($row2['total']);
                mysqli_stmt_close($stmt2);
                $update = "UPDATE quotations SET total_amount = ?, grand_total = ? WHERE id = ?";
                $stmt3 = mysqli_prepare($conn, $update);
                mysqli_stmt_bind_param($stmt3, "ddi", $new_total, $new_total, $quotation_id);
                mysqli_stmt_execute($stmt3);
                mysqli_stmt_close($stmt3);
                $success = "Item added and quotation total updated.";
            } else {
                $error = "Failed to add item.";
            }
            mysqli_stmt_close($stmt);
        }
    }

    // Edit item
    if ($action === 'edit_item') {
        $item_id = intval($_POST['item_id']);
        $quotation_id = intval($_POST['quotation_id']);
        $item_name = trim($_POST['item_name']);
        $quantity = floatval($_POST['quantity']);
        $unit = trim($_POST['unit']);
        $unit_price = floatval($_POST['unit_price']);
        $description = trim($_POST['description']);
        $update = "UPDATE quotation_items SET item_name=?, description=?, quantity=?, unit=?, unit_price=? WHERE id=?";
        $stmt = mysqli_prepare($conn, $update);
        mysqli_stmt_bind_param($stmt, "ssddsi", $item_name, $description, $quantity, $unit, $unit_price, $item_id);
        if (mysqli_stmt_execute($stmt)) {
            $sum_total = "SELECT SUM(quantity * unit_price) AS total FROM quotation_items WHERE quotation_id = ?";
            $stmt2 = mysqli_prepare($conn, $sum_total);
            mysqli_stmt_bind_param($stmt2, "i", $quotation_id);
            mysqli_stmt_execute($stmt2);
            $res2 = mysqli_stmt_get_result($stmt2);
            $row2 = mysqli_fetch_assoc($res2);
            $new_total = floatval($row2['total']);
            mysqli_stmt_close($stmt2);
            $update_q = "UPDATE quotations SET total_amount = ?, grand_total = ? WHERE id = ?";
            $stmt3 = mysqli_prepare($conn, $update_q);
            mysqli_stmt_bind_param($stmt3, "ddi", $new_total, $new_total, $quotation_id);
            mysqli_stmt_execute($stmt3);
            mysqli_stmt_close($stmt3);
            $success = "Item updated.";
        } else {
            $error = "Failed to update item.";
        }
        mysqli_stmt_close($stmt);
    }

    // Delete item
    if ($action === 'delete_item') {
        $item_id = intval($_POST['item_id']);
        $quotation_id = intval($_POST['quotation_id']);
        $delete = "DELETE FROM quotation_items WHERE id = ?";
        $stmt = mysqli_prepare($conn, $delete);
        mysqli_stmt_bind_param($stmt, "i", $item_id);
        if (mysqli_stmt_execute($stmt)) {
            $sum_total = "SELECT SUM(quantity * unit_price) AS total FROM quotation_items WHERE quotation_id = ?";
            $stmt2 = mysqli_prepare($conn, $sum_total);
            mysqli_stmt_bind_param($stmt2, "i", $quotation_id);
            mysqli_stmt_execute($stmt2);
            $res2 = mysqli_stmt_get_result($stmt2);
            $row2 = mysqli_fetch_assoc($res2);
            $new_total = floatval($row2['total']);
            mysqli_stmt_close($stmt2);
            $update_q = "UPDATE quotations SET total_amount = ?, grand_total = ? WHERE id = ?";
            $stmt3 = mysqli_prepare($conn, $update_q);
            mysqli_stmt_bind_param($stmt3, "ddi", $new_total, $new_total, $quotation_id);
            mysqli_stmt_execute($stmt3);
            mysqli_stmt_close($stmt3);
            $success = "Item deleted.";
        } else {
            $error = "Failed to delete item.";
        }
        mysqli_stmt_close($stmt);
    }

    // Finalize
    if ($action === 'finalize') {
        $quotation_id = intval($_POST['quotation_id']);
        $check = "SELECT id FROM quotations WHERE id = ? AND quotation_request_id = ?";
        $stmt = mysqli_prepare($conn, $check);
        mysqli_stmt_bind_param($stmt, "ii", $quotation_id, $request_id);
        mysqli_stmt_execute($stmt);
        $check_res = mysqli_stmt_get_result($stmt);
        if (mysqli_num_rows($check_res) == 0) {
            $error = "Invalid quotation selected.";
        } else {
            $update = "UPDATE quotation_requests SET status = 'QS Finalized', final_quotation_id = ?, updated_at = NOW() WHERE id = ? AND status = 'With QS'";
            $stmt = mysqli_prepare($conn, $update);
            mysqli_stmt_bind_param($stmt, "ii", $quotation_id, $request_id);
            if (mysqli_stmt_execute($stmt)) {
                $update_q = "UPDATE quotations SET status = 'Finalized' WHERE id = ?";
                $stmt2 = mysqli_prepare($conn, $update_q);
                mysqli_stmt_bind_param($stmt2, "i", $quotation_id);
                mysqli_stmt_execute($stmt2);
                mysqli_stmt_close($stmt2);
                $success = "Quotation finalized. Request moved to QS Finalized status.";
                $request['status'] = 'QS Finalized';
                $request['final_quotation_id'] = $quotation_id;

                $notifyEmployees = [];

                $managerId = (int)($request['manager_employee_id'] ?? 0);
                $tlId = (int)($request['team_lead_employee_id'] ?? 0);
                $projectEngineerId = (int)($request['project_engineer_id'] ?? 0);
                $requestedById = (int)($request['requested_by'] ?? 0);

                if ($managerId > 0) $notifyEmployees[$managerId] = 'Manager';
                if ($tlId > 0) $notifyEmployees[$tlId] = 'Team Lead';
                if ($projectEngineerId > 0) $notifyEmployees[$projectEngineerId] = 'Project Engineer';
                if ($requestedById > 0) $notifyEmployees[$requestedById] = 'Requester';

                foreach ($notifyEmployees as $toEmployeeId => $roleLabel) {
                    if ((int)$toEmployeeId === (int)$empId) {
                        continue;
                    }

                    createNotificationCurrentDb(
                        $conn,
                        (int)$toEmployeeId,
                        'QS quotation finalized',
                        'QS finalized quotation for request ' . ($request['request_no'] ?? '') . ' - ' . ($request['project_name'] ?? ''),
                        'quotation_requests',
                        $request_id,
                        'view-quotation-request.php?id=' . $request_id,
                        'quotation'
                    );
                }

                logQsQuotationActivity(
                    $conn,
                    $empId,
                    'UPDATE',
                    'QS finalized quotation for request ' . ($request['request_no'] ?? ''),
                    $request_id,
                    [
                        'final_quotation_id' => $quotation_id,
                        'request_no' => $request['request_no'] ?? '',
                        'project_name' => $request['project_name'] ?? '',
                        'notified' => array_values($notifyEmployees)
                    ]
                );
            } else {
                $error = "Failed to finalize.";
            }
            mysqli_stmt_close($stmt);
        }
        
    }
}

// Fetch quotations for this request
$quotations = [];
$queries = "SELECT q.*, d.dealer_name FROM quotations q LEFT JOIN quotation_dealers d ON q.dealer_id = d.id WHERE q.quotation_request_id = ? ORDER BY q.total_amount ASC";
$stmt = mysqli_prepare($conn, $queries);
mysqli_stmt_bind_param($stmt, "i", $request_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$quotations = mysqli_fetch_all($res, MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

// Prefetch quotation items BEFORE rendering the page.
// Some included template files can close/reuse $conn, so do not run mysqli queries inside HTML loops.
$quotation_items_by_id = [];
if (!empty($quotations)) {
    $quotation_ids = array_map('intval', array_column($quotations, 'id'));
    $quotation_ids = array_values(array_filter($quotation_ids));

    if (!empty($quotation_ids)) {
        $placeholders = implode(',', array_fill(0, count($quotation_ids), '?'));
        $types = str_repeat('i', count($quotation_ids));
        $items_sql = "SELECT * FROM quotation_items WHERE quotation_id IN ($placeholders) ORDER BY quotation_id, id";
        $items_stmt = mysqli_prepare($conn, $items_sql);

        if ($items_stmt) {
            mysqli_stmt_bind_param($items_stmt, $types, ...$quotation_ids);
            mysqli_stmt_execute($items_stmt);
            $items_res = mysqli_stmt_get_result($items_stmt);

            while ($item_row = mysqli_fetch_assoc($items_res)) {
                $qid = (int)$item_row['quotation_id'];
                if (!isset($quotation_items_by_id[$qid])) {
                    $quotation_items_by_id[$qid] = [];
                }
                $quotation_items_by_id[$qid][] = $item_row;
            }

            mysqli_stmt_close($items_stmt);
        }
    }
}

// Fetch active dealers for dropdown
$dealers = [];
$dealer_query = "SELECT id, dealer_name FROM quotation_dealers WHERE status = 'Active' ORDER BY dealer_name";
$dealer_res = mysqli_query($conn, $dealer_query);
if ($dealer_res) {
    while ($row = mysqli_fetch_assoc($dealer_res)) {
        $dealers[] = $row;
    }
}

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function safeDate($v, $dash='—'){
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00') return $dash;
    $ts = strtotime($v);
    return $ts ? date('d M Y', $ts) : e($v);
}
function formatCurrency($amount) {
    if ($amount === null || $amount == 0) return '—';
    return '₹ ' . number_format($amount, 2);
}
function getStatusBadge($status) {
    $status = trim((string)$status);
    $map = [
        'With QS' => ['pending', 'bi-arrow-right'],
        'QS Finalized' => ['ontrack', 'bi-check-circle'],
        'Approved' => ['ontrack', 'bi-check-circle-fill'],
        'Rejected' => ['atrisk', 'bi-x-circle'],
        'Cancelled' => ['neutral', 'bi-x']
    ];
    $item = $map[$status] ?? ['neutral', 'bi-question'];
    return '<span class="badge-pill ' . $item[0] . '"><i class="bi ' . $item[1] . '"></i>' . e($status ?: '—') . '</span>';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Manage Quotation - <?php echo e($request['request_no']); ?> - TEK-C</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />
    <style>
        :root{
            --page-bg:#f5f7fb;
            --card-bg:#ffffff;
            --border:#e5e7eb;
            --text:#111827;
            --muted:#6b7280;
            --soft:#f8fafc;
            --shadow:0 10px 26px rgba(15,23,42,.055);
            --radius:15px;
            --blue:#2f80ed;
            --green:#27ae60;
            --orange:#f2994a;
            --red:#eb5757;
            --purple:#7c3aed;
        }

        body{background:var(--page-bg);}
        .content-scroll{flex:1 1 auto;overflow:auto;padding:16px;}
        .projects-wrapper{width:100%;}

        .page-heading{
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap:12px;
            margin-bottom:14px;
        }

        .page-heading h1{
            font-size:19px;
            font-weight:950;
            color:var(--text);
            margin:0;
        }

        .page-heading p{
            margin:3px 0 0;
            color:var(--muted);
            font-size:12px;
            font-weight:650;
        }

        .primary-btn,.secondary-btn,.success-btn,.danger-btn,.btn-action{
            min-height:36px;
            padding:0 14px;
            border-radius:11px;
            font-size:12px;
            font-weight:900;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:7px;
            text-decoration:none;
            white-space:nowrap;
            line-height:1;
        }

        .primary-btn{border:0;background:#111827;color:#fff;}
        .primary-btn:hover{background:#020617;color:#fff;}
        .success-btn{border:0;background:#16a34a;color:#fff;}
        .success-btn:hover{background:#15803d;color:#fff;}
        .danger-btn{border:0;background:#dc2626;color:#fff;}
        .danger-btn:hover{background:#b91c1c;color:#fff;}

        .secondary-btn,.btn-action{
            border:1px solid var(--border);
            background:#fff;
            color:#334155;
        }

        .secondary-btn:hover,.btn-action:hover{
            border-color:#cbd5e1;
            background:#f8fafc;
            color:#111827;
        }

        .panel{
            background:var(--card-bg);
            border:1px solid var(--border);
            border-radius:var(--radius);
            box-shadow:var(--shadow);
            padding:13px;
            margin-bottom:14px;
            height:auto;
        }

        .panel-header{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            margin-bottom:12px;
        }

        .panel-title{
            font-weight:950;
            font-size:14px;
            color:var(--text);
            margin:0;
            display:flex;
            align-items:center;
            gap:8px;
        }

        .panel-title i{color:var(--blue);font-size:16px;}
        .panel-subtitle{color:#64748b;font-size:11px;font-weight:700;margin-top:2px;}

        .panel-menu{
            width:34px;
            height:34px;
            border-radius:11px;
            border:1px solid var(--border);
            background:#fff;
            display:grid;
            place-items:center;
            color:#64748b;
            flex:0 0 auto;
        }

        .info-grid{
            display:grid;
            grid-template-columns:repeat(auto-fill,minmax(230px,1fr));
            gap:10px;
            margin-top:10px;
        }

        .info-item{
            padding:10px 11px;
            background:#f8fafc;
            border-radius:12px;
            border:1px solid #eef2f7;
        }

        .info-label{
            font-size:10px;
            font-weight:950;
            color:#64748b;
            text-transform:uppercase;
            margin-bottom:3px;
        }

        .info-value{
            font-size:12px;
            font-weight:900;
            color:#111827;
            word-break:break-word;
        }

        .section-heading{
            font-size:12px;
            font-weight:950;
            color:#111827;
            margin:0 0 8px;
            display:flex;
            align-items:center;
            gap:7px;
        }

        .description-box{
            background:#f8fafc;
            border-radius:13px;
            padding:12px;
            border:1px solid #eef2f7;
            line-height:1.5;
            font-size:12px;
            font-weight:750;
            color:#334155;
        }

        .badge-pill{
            border-radius:999px;
            padding:5px 8px;
            font-weight:900;
            font-size:10px;
            display:inline-flex;
            align-items:center;
            gap:6px;
            border:1px solid transparent;
            text-decoration:none;
            white-space:nowrap;
        }

        .ontrack{color:#15803d;background:#dcfce7;border-color:#bbf7d0;}
        .progressing{color:#2563eb;background:#dbeafe;border-color:#bfdbfe;}
        .pending{color:#6d28d9;background:#ede9fe;border-color:#ddd6fe;}
        .atrisk{color:#b91c1c;background:#fee2e2;border-color:#fecaca;}
        .neutral{color:#475569;background:#f1f5f9;border-color:#e2e8f0;}
        .warning{color:#b45309;background:#ffedd5;border-color:#fed7aa;}

        .btn-action{
            min-height:32px;
            padding:0 10px;
            border-radius:10px;
        }

        .btn-action.edit:hover,.btn-action.items:hover{
            color:#2563eb;
            border-color:#bfdbfe;
            background:#eff6ff;
        }

        .btn-action.finalize:hover{
            color:#15803d;
            border-color:#86efac;
            background:#dcfce7;
        }

        .btn-action.delete:hover{
            color:#b91c1c;
            border-color:#fecaca;
            background:#fee2e2;
        }

        .file-list{display:flex;flex-direction:column;gap:8px;margin-top:10px;}

        .file-item{
            display:flex;
            align-items:center;
            gap:10px;
            padding:8px 10px;
            background:#f8fafc;
            border-radius:11px;
            border:1px solid #eef2f7;
        }

        .file-icon{
            width:32px;
            height:32px;
            background:var(--blue);
            border-radius:9px;
            display:flex;
            align-items:center;
            justify-content:center;
            color:white;
            font-size:15px;
            flex:0 0 auto;
        }

        .file-icon.pdf{background:#dc2626;}
        .file-icon.image{background:#8b5cf6;}
        .file-icon.doc{background:#2f80ed;}
        .file-icon.excel{background:#16a34a;}

        .file-details{flex:1;min-width:0;}
        .file-name{font-weight:900;color:#111827;font-size:12px;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .file-meta{font-size:10px;color:#64748b;font-weight:700;}
        .document-link{color:#2563eb;text-decoration:none;font-weight:900;font-size:11px;}

        .file-actions{
            display:flex;
            align-items:center;
            gap:6px;
            flex-wrap:wrap;
        }

        .doc-action-btn{
            min-height:30px;
            padding:0 9px;
            border-radius:9px;
            border:1px solid var(--border);
            background:#fff;
            color:#334155;
            font-size:10.5px;
            font-weight:900;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:5px;
            white-space:nowrap;
        }

        .doc-action-btn:hover{
            background:#f8fafc;
            border-color:#cbd5e1;
            color:#111827;
        }

        .doc-action-btn.view:hover{
            color:#2563eb;
            border-color:#bfdbfe;
            background:#eff6ff;
        }


        .doc-missing-path{
            color:#b45309;
            background:#fffbeb;
            border:1px solid #fde68a;
            border-radius:999px;
            padding:5px 8px;
            font-size:10.5px;
            font-weight:900;
            display:inline-flex;
            align-items:center;
            gap:5px;
            white-space:nowrap;
        }

        .doc-action-btn.download:hover{
            color:#15803d;
            border-color:#bbf7d0;
            background:#dcfce7;
        }


        .compact-table-wrap{
            width:100%;
            border:1px solid var(--border);
            border-radius:13px;
            overflow:hidden;
            background:#fff;
        }

        .compact-table{width:100%;margin:0;table-layout:auto;}

        .compact-table thead th{
            background:var(--soft);
            color:#64748b;
            font-size:10px;
            text-transform:uppercase;
            font-weight:900;
            border-bottom:1px solid var(--border)!important;
            padding:8px 9px;
        }

        .compact-table tbody td{
            padding:8px 9px;
            vertical-align:middle;
            border-color:#eef2f7;
            color:#334155;
            font-weight:700;
            font-size:11.5px;
        }

        .compact-table tbody tr:hover{background:#fbfdff;}
        .quotation-row.selected{background:#f0fdf4;box-shadow:inset 3px 0 0 #16a34a;}
        .table-primary-text{color:#111827;font-size:11.5px;font-weight:950;}
        .badge-final{background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;padding:5px 8px;border-radius:999px;font-size:10px;font-weight:900;display:inline-flex;align-items:center;gap:5px;}

        .form-label{
            font-size:11px;
            font-weight:900;
            color:#475569;
            text-transform:uppercase;
            margin-bottom:6px;
        }

        .form-control,.form-select{
            min-height:38px;
            border:1px solid var(--border);
            border-radius:11px;
            font-size:12px;
            font-weight:800;
            color:#111827;
            padding:8px 11px;
            background:#fff;
        }

        textarea.form-control{min-height:78px;}

        .modal-content{
            border:1px solid var(--border);
            border-radius:16px;
            box-shadow:0 24px 55px rgba(15,23,42,.18);
        }

        .modal-header{border-bottom:1px solid #eef2f7;}
        .modal-title{font-size:15px;font-weight:950;color:#111827;}

        .alert{
            border-radius:14px;
            border:1px solid transparent;
            box-shadow:var(--shadow);
            font-size:12px;
            font-weight:850;
            margin-bottom:14px;
        }

        .alert-success{background:#dcfce7;border-color:#bbf7d0;color:#166534;}
        .alert-danger{background:#fee2e2;border-color:#fecaca;color:#991b1b;}
        .alert-info{background:#eff6ff;border-color:#bfdbfe;color:#1e40af;}

        @media(max-width:991.98px){
            .main{margin-left:0!important;width:100%!important;max-width:100%!important;}
            .sidebar{position:fixed!important;transform:translateX(-100%);z-index:1040!important;}
            .sidebar.open,.sidebar.active,.sidebar.show{transform:translateX(0)!important;}
        }

        @media(max-width:1199px){
            .compact-table thead{display:none;}
            .compact-table,.compact-table tbody,.compact-table tr,.compact-table td{display:block;width:100%;}
            .compact-table tbody tr{border-bottom:1px solid var(--border);padding:10px;}
            .compact-table tbody td{
                border:0;
                display:flex;
                justify-content:space-between;
                gap:12px;
            }
            .compact-table tbody td::before{
                content:attr(data-label);
                font-size:10px;
                font-weight:900;
                color:#64748b;
                text-transform:uppercase;
                flex:0 0 105px;
            }
            .compact-table tbody td:first-child{display:block;}
            .compact-table tbody td:first-child::before{display:none;}
        }

        @media(max-width:768px){
            .content-scroll{padding:12px 10px!important;}
            .container-fluid.projects-wrapper{padding-left:0!important;padding-right:0!important;}
            .page-heading{align-items:flex-start;flex-direction:column;}
            .panel{padding:12px;}
            .primary-btn,.secondary-btn,.success-btn,.danger-btn{width:100%;}
            .file-item{align-items:flex-start;}
        }
    </style>
</head>
<body>
<div class="app">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main" aria-label="Main">
        <?php include 'includes/topbar.php'; ?>

        <div id="contentScroll" class="content-scroll">
            <div class="container-fluid projects-wrapper px-0">

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i> <?php echo e($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo e($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="page-heading">
                    <div>
                        <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                            <h1>Manage Quotation</h1>
                            <?php echo getStatusBadge($request['status']); ?>
                            <span class="badge-pill pending">
                                <i class="bi bi-person-badge"></i>
                                QS Workspace
                            </span>
                        </div>
                        <p>Request #<?php echo e($request['request_no']); ?> • Add, compare, and finalize dealer quotations.</p>
                    </div>

                    <a href="qs-quotations.php" class="secondary-btn">
                        <i class="bi bi-arrow-left"></i>
                        Back
                    </a>
                </div>

                <!-- Request Summary Panel -->
                <div class="panel mb-4">
                    <div class="panel-header">
                        <div>
                            <h3 class="panel-title"><i class="bi bi-file-earmark-text"></i> Request Details</h3>
                            <div class="panel-subtitle">Project, client, team and requirement details</div>
                        </div>
                        <button class="panel-menu"><i class="bi bi-three-dots"></i></button>
                    </div>
                    <div class="info-grid mb-3">
                        <div class="info-item"><div class="info-label">Project</div><div class="info-value"><?php echo e($request['project_name']); ?></div></div>
                        <div class="info-item"><div class="info-label">Client</div><div class="info-value"><?php echo e($request['client_name'] ?? '—'); ?></div></div>
                        <div class="info-item"><div class="info-label">Quotation Type</div><div class="info-value"><?php echo e($request['quotation_type']); ?></div></div>
                        <div class="info-item"><div class="info-label">Required By</div><div class="info-value"><?php echo safeDate($request['required_by_date']); ?></div></div>
                        <div class="info-item"><div class="info-label">Manager</div><div class="info-value"><?php echo e($request['manager_name'] ?? '—'); ?></div></div>
                        <div class="info-item"><div class="info-label">Team Lead</div><div class="info-value"><?php echo e($request['team_lead_name'] ?? '—'); ?></div></div>
                    </div>
                    <h4 class="section-heading"><i class="bi bi-card-text"></i>Description</h4>
                    <div class="description-box mb-3"><?php echo nl2br(e($request['description'])); ?></div>
                    <?php if (!empty($request['specifications'])): ?>
                        <h4 class="section-heading"><i class="bi bi-list-check"></i>Specifications</h4>
                        <div class="description-box"><?php echo nl2br(e($request['specifications'])); ?></div>
                    <?php endif; ?>

                    <div class="mt-3">
                        <h4 class="section-heading"><i class="bi bi-paperclip"></i>Uploaded Drawings & Additional Documents</h4>
                        <?php if (empty($additional_documents)): ?>
                            <div class="description-box text-muted">
                                <i class="bi bi-inbox me-1"></i>
                                No additional documents uploaded with this request.
                            </div>
                        <?php else: ?>
                            <div class="file-list">
                                <?php foreach ($additional_documents as $doc): ?>
                                    <?php
                                        $docPath = (string)($doc['url'] ?? buildProjectEngineerFileUrl($doc['file_path'] ?? ''));
                                        $docName = (string)($doc['file_name'] ?? basename($docPath));
                                        $docGroup = (string)($doc['group'] ?? 'Document');
                                        $ext = strtolower(pathinfo($docName ?: $docPath, PATHINFO_EXTENSION));
                                        $iconClass = 'doc';
                                        $icon = 'bi-file-earmark-text';
                                        if ($ext === 'pdf') { $iconClass = 'pdf'; $icon = 'bi-file-earmark-pdf'; }
                                        elseif (in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) { $iconClass = 'image'; $icon = 'bi-file-earmark-image'; }
                                        elseif (in_array($ext, ['xls','xlsx','csv'], true)) { $iconClass = 'excel'; $icon = 'bi-file-earmark-spreadsheet'; }
                                    ?>
                                    <div class="file-item">
                                        <div class="file-icon <?php echo e($iconClass); ?>">
                                            <i class="bi <?php echo e($icon); ?>"></i>
                                        </div>
                                        <div class="file-details">
                                            <div class="file-name"><?php echo e($docName ?: 'Document'); ?></div>
                                            <div class="file-meta">
                                                <span class="badge-pill neutral" style="padding:2px 6px;font-size:9px;"><?php echo e($docGroup ?? 'Document'); ?></span>
                                                <?php echo !empty($docGroup) ? ' • ' : ''; ?>
                                                <?php echo e(formatFileSizeDisplay($doc['file_size'] ?? '')); ?>
                                                <?php if (!empty($doc['uploaded_at'])): ?>
                                                    <?php echo formatFileSizeDisplay($doc['file_size'] ?? '') ? ' • ' : ''; ?>
                                                    Uploaded <?php echo e(safeDate($doc['uploaded_at'])); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="file-actions">
                                            
                                                <a href="<?php echo e($docPath); ?>" target="_blank" rel="noopener" class="doc-action-btn view">
                                                    <i class="bi bi-eye"></i>
                                                    View
                                                </a>
                                                <a href="<?php echo e($docPath); ?>" download="<?php echo e($docName ?: basename($docPath)); ?>" class="doc-action-btn download">
                                                    <i class="bi bi-download"></i>
                                                    Download
                                                </a>
                                            
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                </div>

                <!-- Quotations Section -->
                <div class="panel mb-4">
                    <div class="panel-header">
                        <div>
                            <h3 class="panel-title"><i class="bi bi-table"></i> Quotations Received</h3>
                            <div class="panel-subtitle">Compare dealer quotations and finalize the selected offer</div>
                        </div>
                        <?php if ($request['status'] === 'With QS'): ?>
                            <button type="button" class="primary-btn" data-bs-toggle="modal" data-bs-target="#addQuotationModal">
                                <i class="bi bi-plus-lg"></i>
                                Add Quotation
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="compact-table-wrap">
                        <table class="table compact-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Dealer</th>
                                    <th>Total Amount</th>
                                    <th>Delivery Terms</th>
                                    <th>Payment Terms</th>
                                    <th>Warranty</th>
                                    <th>Document</th>
                                    <th>Items</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($quotations)): ?>
                                    <tr><td colspan="8"><div class="text-center text-muted fw-bold py-4"><i class="bi bi-inbox d-block fs-2 mb-2"></i>No quotations added yet.</div></td></tr>
                                <?php else: ?>
                                    <?php foreach ($quotations as $q): 
                                        $isFinal = ($request['final_quotation_id'] == $q['id']);
                                        // Items are prefetched before includes/rendering to avoid using a closed mysqli connection.
                                        $items = $quotation_items_by_id[(int)$q['id']] ?? [];
                                    ?>
                                        <tr class="quotation-row <?php echo $isFinal ? 'selected' : ''; ?>">
                                            <td data-label="Dealer"><span class="table-primary-text"><?php echo e($q['dealer_name'] ?? '—'); ?></span></td>
                                            <td data-label="Amount" class="table-primary-text"><?php echo formatCurrency($q['total_amount']); ?></td>
                                            <td data-label="Delivery"><?php echo e($q['delivery_terms'] ?? '—'); ?></td>
                                            <td data-label="Payment"><?php echo e($q['payment_terms'] ?? '—'); ?></td>
                                            <td data-label="Warranty"><?php echo e($q['warranty'] ?? '—'); ?></td>
                                            <td data-label="Document">
                                                <?php if (!empty($q['quotation_document'])): ?>
                                                    <div class="file-actions">
                                                        <a href="<?php echo e(buildQuotationDocumentUrl($q['quotation_document'])); ?>" target="_blank" rel="noopener" class="doc-action-btn view">
                                                            <i class="bi bi-eye"></i>
                                                            View
                                                        </a>
                                                        <a href="<?php echo e(buildQuotationDocumentUrl($q['quotation_document'])); ?>" download="<?php echo e(basename($q['quotation_document'])); ?>" class="doc-action-btn download">
                                                            <i class="bi bi-download"></i>
                                                            Download
                                                        </a>
                                                    </div>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Items">
                                                <button type="button" class="btn-action items" data-bs-toggle="modal" data-bs-target="#itemsModal" data-quotation-id="<?php echo $q['id']; ?>" data-dealer="<?php echo e($q['dealer_name']); ?>" data-total="<?php echo $q['total_amount']; ?>">
                                                    <i class="bi bi-list-ul"></i> <?php echo count($items); ?> items
                                                </button>
                                            </td>
                                            <td data-label="Actions" class="text-end">
                                                <?php if ($request['status'] === 'With QS'): ?>
                                                    <button class="btn-action edit" data-bs-toggle="modal" data-bs-target="#editQuotationModal" 
                                                        data-id="<?php echo $q['id']; ?>" 
                                                        data-dealer-id="<?php echo $q['dealer_id']; ?>" 
                                                        data-amount="<?php echo $q['total_amount']; ?>" 
                                                        data-delivery="<?php echo e($q['delivery_terms']); ?>" 
                                                        data-payment="<?php echo e($q['payment_terms']); ?>" 
                                                        data-warranty="<?php echo e($q['warranty']); ?>" 
                                                        data-remarks="<?php echo e($q['remarks']); ?>"
                                                        data-document="<?php echo e(buildQuotationDocumentUrl($q['quotation_document'])); ?>">
                                                        <i class="bi bi-pencil"></i> Edit
                                                    </button>
                                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this quotation?');">
                                                        <input type="hidden" name="action" value="delete_quotation">
                                                        <input type="hidden" name="quotation_id" value="<?php echo $q['id']; ?>">
                                                        <button type="submit" class="btn-action delete"><i class="bi bi-trash"></i> Delete</button>
                                                    </form>
                                                    <?php if (!$isFinal): ?>
                                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Finalize this quotation? This will move the request to QS Finalized.');">
                                                            <input type="hidden" name="action" value="finalize">
                                                            <input type="hidden" name="quotation_id" value="<?php echo $q['id']; ?>">
                                                            <button type="submit" class="btn-action finalize"><i class="bi bi-check-lg"></i> Finalize</button>
                                                        </form>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                <?php if ($isFinal): ?>
                                                    <span class="badge-final"><i class="bi bi-star-fill"></i> Finalized</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if ($request['status'] === 'QS Finalized'): ?>
                    <div class="alert alert-info"><i class="bi bi-info-circle"></i> This request has been finalized. To make changes, you can reopen it from the dashboard.</div>
                <?php endif; ?>
            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </main>
</div>

<!-- Add Quotation Modal -->
<div class="modal fade" id="addQuotationModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title">Add Quotation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_quotation">
                    <div class="mb-3">
                        <label class="form-label">Dealer *</label>
                        <select name="dealer_id" class="form-select" required>
                            <option value="">-- Select Dealer --</option>
                            <?php foreach ($dealers as $d): ?>
                                <option value="<?php echo $d['id']; ?>"><?php echo e($d['dealer_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3"><label class="form-label">Total Amount (₹) *</label><input type="number" step="0.01" name="total_amount" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">Delivery Terms</label><input type="text" name="delivery_terms" class="form-control"></div>
                    <div class="mb-3"><label class="form-label">Payment Terms</label><input type="text" name="payment_terms" class="form-control"></div>
                    <div class="mb-3"><label class="form-label">Warranty</label><input type="text" name="warranty" class="form-control"></div>
                    <div class="mb-3"><label class="form-label">Remarks</label><textarea name="remarks" class="form-control" rows="2"></textarea></div>
                    <div class="mb-3">
                        <label class="form-label">Quotation Document (PDF/Image/Excel)</label>
                        <input type="file" name="quotation_document" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.xls,.xlsx,.doc,.docx">
                        <small class="text-muted">Max 5MB. Allowed: PDF, JPG, PNG, Excel, Word.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="secondary-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="primary-btn">Save Quotation</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Quotation Modal -->
<div class="modal fade" id="editQuotationModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Quotation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_quotation">
                    <input type="hidden" name="quotation_id" id="edit_quotation_id">
                    <div class="mb-3">
                        <label class="form-label">Dealer *</label>
                        <select name="dealer_id" id="edit_dealer_id" class="form-select" required>
                            <option value="">-- Select Dealer --</option>
                            <?php foreach ($dealers as $d): ?>
                                <option value="<?php echo $d['id']; ?>"><?php echo e($d['dealer_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3"><label class="form-label">Total Amount (₹) *</label><input type="number" step="0.01" name="total_amount" id="edit_total_amount" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">Delivery Terms</label><input type="text" name="delivery_terms" id="edit_delivery_terms" class="form-control"></div>
                    <div class="mb-3"><label class="form-label">Payment Terms</label><input type="text" name="payment_terms" id="edit_payment_terms" class="form-control"></div>
                    <div class="mb-3"><label class="form-label">Warranty</label><input type="text" name="warranty" id="edit_warranty" class="form-control"></div>
                    <div class="mb-3"><label class="form-label">Remarks</label><textarea name="remarks" id="edit_remarks" class="form-control" rows="2"></textarea></div>
                    <div class="mb-3">
                        <label class="form-label">Quotation Document (PDF/Image/Excel)</label>
                        <input type="file" name="quotation_document" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.xls,.xlsx,.doc,.docx">
                        <small class="text-muted">Leave blank to keep existing. Max 5MB.</small>
                        <div id="current_document" class="mt-2"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="secondary-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="primary-btn">Update Quotation</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Items Modal -->
<div class="modal fade" id="itemsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Itemized Breakdown</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="itemsModalBody">
                <!-- dynamic content -->
            </div>
            <div class="modal-footer">
                <button type="button" class="secondary-btn" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>
<script>
    // Populate edit modal with data
    document.querySelectorAll('[data-bs-target="#editQuotationModal"]').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('edit_quotation_id').value = this.dataset.id;
            document.getElementById('edit_dealer_id').value = this.dataset.dealerId;
            document.getElementById('edit_total_amount').value = this.dataset.amount;
            document.getElementById('edit_delivery_terms').value = this.dataset.delivery;
            document.getElementById('edit_payment_terms').value = this.dataset.payment;
            document.getElementById('edit_warranty').value = this.dataset.warranty;
            document.getElementById('edit_remarks').value = this.dataset.remarks;
            if (this.dataset.document) {
                document.getElementById('current_document').innerHTML = `
                    <div class="file-actions mt-2">
                        <a href="${this.dataset.document}" target="_blank" class="doc-action-btn view">
                            <i class="bi bi-eye"></i> View Current
                        </a>
                        <a href="${this.dataset.document}" download class="doc-action-btn download">
                            <i class="bi bi-download"></i> Download Current
                        </a>
                    </div>
                `;
            } else {
                document.getElementById('current_document').innerHTML = '';
            }
        });
    });

    // Load items for a quotation
    document.querySelectorAll('[data-bs-target="#itemsModal"]').forEach(btn => {
        btn.addEventListener('click', function() {
            const quotationId = this.dataset.quotationId;
            const dealerName = this.dataset.dealer;
            const total = this.dataset.total;
            fetch(`ajax-get-items.php?quotation_id=${quotationId}`)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('itemsModalBody').innerHTML = `
                        <h6>Dealer: ${dealerName}</h6>
                        <p>Total Amount: ₹ ${parseFloat(total).toLocaleString('en-IN', {minimumFractionDigits:2})}</p>
                        <hr>
                        <div class="table-responsive">
                            <table class="table compact-table items-table">
                                <thead>
                                    <tr><th>Item</th><th>Description</th><th>Qty</th><th>Unit</th><th>Unit Price</th><th>Total</th><th>Actions</th></tr>
                                </thead>
                                <tbody>${html}</tbody>
                            </table>
                        </div>
                        <div class="mt-2">
                            <button type="button" class="btn-action" onclick="showAddItemForm(${quotationId})"><i class="bi bi-plus-lg"></i> Add Item</button>
                        </div>
                    `;
                });
        });
    });

    function showAddItemForm(quotationId) {
        const formHtml = `
            <form method="POST" class="mt-3 border-top pt-3" id="addItemForm">
                <input type="hidden" name="action" value="add_item">
                <input type="hidden" name="quotation_id" value="${quotationId}">
                <div class="row g-2">
                    <div class="col-12"><label>Item Name *</label><input type="text" name="item_name" class="form-control" required></div>
                    <div class="col-6"><label>Quantity *</label><input type="number" step="0.01" name="quantity" class="form-control" required></div>
                    <div class="col-6"><label>Unit *</label><input type="text" name="unit" class="form-control" required></div>
                    <div class="col-6"><label>Unit Price (₹) *</label><input type="number" step="0.01" name="unit_price" class="form-control" required></div>
                    <div class="col-12"><label>Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
                    <div class="col-12"><button type="submit" class="primary-btn">Save Item</button></div>
                </div>
            </form>
        `;
        document.getElementById('itemsModalBody').insertAdjacentHTML('beforeend', formHtml);
    }
</script>
</body>
</html>
<?php // Connection is managed by includes/db-config.php / request lifecycle. ?>