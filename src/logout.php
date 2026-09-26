<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/auth.php';

log_out();
?>
<!doctype html>
<html><head><meta charset="utf-8"></head><body>
<script>
  localStorage.removeItem("bnb-mock-role");
  location.href = "login.php";
</script>
</body></html>
