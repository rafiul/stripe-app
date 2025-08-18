<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';


// Require login
require_login();

// Get pagination parameters
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Get total count
$total_stmt = $db->prepare("SELECT COUNT(*) FROM sync_history WHERE user_id = ?");
$total_stmt->bind_param("i", $_SESSION['user_id']);
$total_stmt->execute();
$total_result = $total_stmt->get_result();
$total_rows = $total_result->fetch_row()[0];
$total_pages = ceil($total_rows / $per_page);

// Get history records
$stmt = $db->prepare("SELECT * FROM sync_history 
                     WHERE user_id = ? 
                     ORDER BY created_at DESC 
                     LIMIT ? OFFSET ?");
$stmt->bind_param("iii", $_SESSION['user_id'], $per_page, $offset);
$stmt->execute();
$result = $stmt->get_result();
$history = $result->fetch_all(MYSQLI_ASSOC);

// Include header
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row">
        <div class="custom-col-20">
            <div class="card">
                <div class="card-body sidebar">
                    <h5 class="text-white"><a class="navbar-brand" href="<?php echo BASE_URL; ?>/dashboard.php"><?php echo APP_NAME; ?></a></h5>
                    <?php include __DIR__ . '/includes/nav.php'; ?>
                </div>
            </div>
        </div>
        
        <div class="col-md-9">
            <div class="card mb-4">
                <div class="card-body">
                    <h2 class="card-title mb-4">Sync History <hr></h2>
                    
                    <?php if (empty($history)): ?>
                        <div class="alert alert-info">No sync history found.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Event</th>
                                        <th>Status</th>
                                        <th>Details</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($history as $record): ?>
                                        <tr>
                                            <td><?php echo date('M j, Y g:i a', strtotime($record['created_at'])); ?></td>
                                            <td><?php echo sanitize_output($record['event_type']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    echo $record['status'] === 'success' ? 'success' : 
                                                         ($record['status'] === 'failed' ? 'danger' : 'warning'); ?>">
                                                    <?php echo ucfirst($record['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo sanitize_output(substr($record['message'], 0, 50)) . (strlen($record['message']) > 50 ? '...' : ''); ?></td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-outline-primary" 
                                                    data-bs-toggle="modal" data-bs-target="#detailsModal<?php echo $record['id']; ?>">
                                                    View Details
                                                </button>
                                            </td>
                                        </tr>
                                        
                                        <!-- Details Modal -->
                                        <div class="modal" id="detailsModal<?php echo $record['id']; ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Sync Details</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                           <strong>Event Type:</strong> <?php echo sanitize_output($record['event_type']); ?><br>
                                                            <strong>Status:</strong> <?php echo sanitize_output(ucfirst($record['status'])); ?><br>
                                                            <strong>Date:</strong> <?php echo date('M j, Y g:i a', strtotime($record['created_at'])); ?><br>
                                                            <strong>Message:</strong> <?php echo sanitize_output($record['message']); ?>
                                                        </div>
                                                        
                                                        <div class="mb-3">
                                                            <h6>Data:</h6>
                                                            <pre><?php echo sanitize_output(json_encode(json_decode($record['data']), JSON_PRETTY_PRINT)); ?></pre>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination -->
                        <nav aria-label="Page navigation">
                            <ul class="pagination justify-content-center">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $page - 1; ?>" aria-label="Previous">
                                            <span aria-hidden="true">&laquo;</span>
                                        </a>
                                    </li>
                                <?php endif; ?>
                                
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $page + 1; ?>" aria-label="Next">
                                            <span aria-hidden="true">&raquo;</span>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// Include footer
require_once __DIR__ . '/includes/footer.php';
?>