<?php
// Public entry point for the admin/staff system: www.karimunjawaexplore.com/admin
// view=system forces the main staff dashboard even if the session belongs to an owner/admin role.
header('Location: /login.php?biz=sunsea&view=system');
exit;
