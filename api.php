
<?php
session_start();
header("Content-Type: application/json; charset=UTF-8");

require_once "db.php";

function sendResponse($status, $message = "", $data = null, $code = 200) {
    http_response_code($code);
    $result = ["status" => $status];

    if ($message !== "") {
        $result["message"] = $message;
    }

    if ($data !== null) {
        $result["data"] = $data;
    }

    echo json_encode($result);
    exit;
}

function checkPermission($roles) {
    $role = $_SESSION["role"] ?? "user";

    if (!in_array($role, $roles, true)) {
        sendResponse("error", "You do not have permission.", null, 403);
    }
}

$action = $_GET["action"] ?? "";
$input = json_decode(file_get_contents("php://input"), true) ?? [];

try {
    switch ($action) {
        case "get_role":
            sendResponse("success", "", [
                "role" => $_SESSION["role"] ?? "user"
            ]);
            break;

        case "set_demo_role":
            if ($_SERVER["REQUEST_METHOD"] !== "POST") {
                sendResponse("error", "POST method required.", null, 405);
            }

            $role = $input["role"] ?? "";

            if (!in_array($role, ["user", "editor", "admin"], true)) {
                sendResponse("error", "Invalid role.", null, 400);
            }

            session_regenerate_id(true);
            $_SESSION["role"] = $role;

            sendResponse("success", "Role changed successfully.", [
                "role" => $role
            ]);
            break;

        case "get_menu":
            $role = $_SESSION["role"] ?? "user";
            $allowed = ["user"];

            if ($role === "editor") {
                $allowed = ["user", "editor"];
            } elseif ($role === "admin") {
                $allowed = ["user", "editor", "admin"];
            }

            $marks = implode(",", array_fill(0, count($allowed), "?"));
            $stmt = $pdo->prepare(
                "SELECT title, link FROM menu_items
                 WHERE min_role IN ($marks) ORDER BY id"
            );
            $stmt->execute($allowed);

            sendResponse("success", "", $stmt->fetchAll());
            break;

        case "read":
            $stmt = $pdo->query("SELECT * FROM items ORDER BY id DESC");
            sendResponse("success", "", $stmt->fetchAll());
            break;

        case "create":
            checkPermission(["editor", "admin"]);

            $name = trim($input["name"] ?? "");
            $category = trim($input["category"] ?? "");
            $price = $input["price"] ?? null;

            if (
                $name === "" || strlen($name) > 100 ||
                $category === "" || strlen($category) > 50 ||
                !is_numeric($price) || !is_finite((float)$price) ||
                (float)$price < 0
            ) {
                sendResponse("error", "Enter valid item details.", null, 400);
            }

            $stmt = $pdo->prepare(
                "INSERT INTO items (name, category, price) VALUES (?, ?, ?)"
            );
            $stmt->execute([$name, $category, $price]);

            sendResponse("success", "Item added successfully.");
            break;

        case "update":
            checkPermission(["editor", "admin"]);

            $id = filter_var($input["id"] ?? null, FILTER_VALIDATE_INT);
            $name = trim($input["name"] ?? "");
            $category = trim($input["category"] ?? "");
            $price = $input["price"] ?? null;

            if (
                !$id || $id < 1 ||
                $name === "" || strlen($name) > 100 ||
                $category === "" || strlen($category) > 50 ||
                !is_numeric($price) || !is_finite((float)$price) ||
                (float)$price < 0
            ) {
                sendResponse("error", "Enter valid item details.", null, 400);
            }

            $check = $pdo->prepare("SELECT id FROM items WHERE id = ?");
            $check->execute([$id]);

            if (!$check->fetch()) {
                sendResponse("error", "Item not found.", null, 404);
            }

            $stmt = $pdo->prepare(
                "UPDATE items SET name = ?, category = ?, price = ? WHERE id = ?"
            );
            $stmt->execute([$name, $category, $price, $id]);

            sendResponse("success", "Item updated successfully.");
            break;

        case "delete":
            checkPermission(["admin"]);

            $id = filter_var($input["id"] ?? null, FILTER_VALIDATE_INT);

            if (!$id || $id < 1) {
                sendResponse("error", "Invalid item ID.", null, 400);
            }

            $stmt = $pdo->prepare("DELETE FROM items WHERE id = ?");
            $stmt->execute([$id]);

            if ($stmt->rowCount() === 0) {
                sendResponse("error", "Item not found.", null, 404);
            }

            sendResponse("success", "Item deleted successfully.");
            break;

        default:
            sendResponse("error", "Unknown API action.", null, 400);
    }
} catch (PDOException $e) {
    error_log($e->getMessage());
    sendResponse("error", "A database operation failed.", null, 500);
}
?>
