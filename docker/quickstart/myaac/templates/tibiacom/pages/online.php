<?php
/**
 * Bloqueio da pagina "Who is Online" apenas neste template.
 *
 * O codigo original continua em system/pages/online.php. O router do MyAAC da
 * prioridade a templates/<template>/pages/ antes de system/pages/
 * (system/router.php -> loadPageFromFileSystem), entao esta pagina passa a
 * responder 404 em /online e ?subtopic=online, sem alterar o nucleo.
 */
defined('MYAAC') or die('Direct access not allowed!');
$title = 'Not Found';

header('HTTP/1.0 404 Not Found');
?>
<h1>Not Found</h1>
<p>The requested URL <?= escapeHtml($_SERVER['REQUEST_URI']); ?> was not found on this server.</p>
