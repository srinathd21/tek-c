<?php
// ajax-get-items.php – returns HTML table rows of items for a quotation

session_start();
require_once 'includes/db-config.php';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

$quotation_id = intval($_GET['quotation_id'] ?? 0);
if ($quotation_id <= 0) {
    echo '<tr><td colspan="6" class="text-muted">No items found.</td></tr>';
    exit;
}

$sql = "SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY id";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $quotation_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$items = mysqli_fetch_all($res, MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

if (empty($items)) {
    echo '<tr><td colspan="6" class="text-muted">No items added yet.</td></tr>';
} else {
    foreach ($items as $item) {
        $total = $item['quantity'] * $item['unit_price'];
        echo '<tr>
                <td>' . htmlspecialchars($item['item_name']) . '</td>
                <td>' . htmlspecialchars($item['description']) . '</td>
                <td>' . number_format($item['quantity'], 2) . '</td>
                <td>' . htmlspecialchars($item['unit']) . '</td>
                <td>₹ ' . number_format($item['unit_price'], 2) . '</td>
                <td>₹ ' . number_format($total, 2) . '</td>
              </tr>';
    }
}
?>