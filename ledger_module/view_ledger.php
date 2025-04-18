<?php
include '../database/findb.php';

// Check if ledger_id is provided
if (!isset($_GET['ledger_id'])) {
    header("Location: search_ledger.php"); // Redirect if no ID provided
    exit();
}

$ledger_id = $_GET['ledger_id'];

// Fetch ledger data with group name
$ledger_query = $conn->prepare("
    SELECT l.*, g.group_name 
    FROM ledgers l
    JOIN groups g ON l.group_id = g.group_id
    WHERE l.ledger_id = ?
");
$ledger_query->bind_param("i", $ledger_id);
$ledger_query->execute();
$ledger_result = $ledger_query->get_result();

if ($ledger_result->num_rows === 0) {
    header("Location: ledger_list.php"); // Redirect if ledger not found
    exit();
}

$ledger = $ledger_result->fetch_assoc();

// Format balance with proper sign based on debit/credit
$formatted_balance = ($ledger['debit_credit'] == 'Debit') 
    ? number_format($ledger['current_balance'], 2)
    : number_format($ledger['current_balance'], 2);

// Get icon based on book type
$book_icon = 'fa-book';
if ($ledger['book_type'] == 'Cash') $book_icon = 'fa-money-bill-wave';
if ($ledger['book_type'] == 'Bank') $book_icon = 'fa-university';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Ledger</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="../styles/form_style.css">
    <style>

/* Two Column Layout */
.ledger-form {
    width: 100%;
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

.form-container {
    max-width: 800px;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
    padding: 25px; /* Increased from 500px to accommodate two columns */
}

/* Two-column layout */
.view-form {
    display: flex;
    flex-wrap: wrap;
    gap: 30px;
}

.form-column {
    flex: 1;
    min-width: 300px;
}

.view-form .form-group {
    margin-bottom: 1.5rem;
}

.view-form label {
    display: block;
    font-weight: 500;
    color: var(--primary-dark);
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 8px;
}

.view-field {
    padding: 12px 15px;
    background-color: #f8f9fa;
    border-radius: 5px;
    border: 1px solid #e9ecef;
    font-size: 1rem;
    color: var(--text-dark);
    transition: all 0.3s ease;
    position: relative;
}

.view-field:hover {
    background-color: #e9ecef;
    border-color: var(--primary-light);
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}

/* Optional: Add a subtle left border accent on hover */
.view-field:hover::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 3px;
    background-color: var(--primary-light);
    border-radius: 3px 0 0 3px;
}

/* For the "None" state to make it visually different */
.view-field:empty::after {
    content: 'None';
    color: #6c757d;
    font-style: italic;
}

.edit-link {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    color: var(--primary-light);
    text-decoration: none;
    font-size: 0.9rem;
    margin-left: 15px;
    transition: color 0.2s ease;
}

.edit-link:hover {
    color: var(--secondary-color);
    text-decoration: underline;
}

.form-group label
{
    color: var(--primary-dark);
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 1.1rem;
    font-weight: 550;
}

.form-group input 
{
    padding: 12px 15px;
    border: 1px solid #ddd;
    border-radius: 5px;
    font-size: 1rem;
    transition: all 0.3s ease;
    font-family: 'Poppins', sans-serif;
}

/* Radio Button Styles */
.radio-group {
    display: flex;
    gap: 1rem;
    margin-top: 0.5rem;
}

.radio-group input
{
    margin-left:5px;
}

.radio-option {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    cursor: pointer;
    position: relative;
    padding-left: 2rem;
    margin-bottom: 0.5rem;
    user-select: none;
    transition: all 0.2s ease;
}

.radio-option input[type="radio"] {
    position: absolute;
    top: 0;
    left: 0;
    height: 1.25rem;
    width: 1.25rem;
    background-color: var(--white);
    border: 2px solid var(--primary-light);
    border-radius: 50%;
    transition: all 0.2s ease;
}

.radio-option input[type="radio"]:checked {
    border-color: var(--accent-green);
    background-color: var(--accent-green);
}

.radio-option input[type="radio"]:checked::after {
    content: "";
    position: absolute;
    display: none;
    top: 50%;
    left: 50%;
    width: 0.625rem;
    height: 0.625rem;
    border-radius: 50%;
    background: var(--white);
    transform: translate(-50%, -50%);
}

/* Balance Summary - Integrated into form */
.balance-summary {
    background-color: rgba(52, 152, 219, 0.05);
    padding: 1rem;
    border-radius: 8px;
    margin: 1.5rem 0;
    border-left: 3px solid var(--primary-light);
    width: 95%;
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    margin-top: 0.5rem;
}

.summary-item {
    text-align: center;
    padding: 0.5rem;
    background-color: rgba(255, 255, 255, 0.7);
    border-radius: 5px;
}

.summary-item div:first-child {
    font-size: 0.9rem;
    color: var( --text-dark);
    font-weight: 500;
    margin-bottom: 0.5rem;
}

.summary-value {
    font-weight: 600;
    font-size: 1.1rem;
}

/* Submit Button Centering */
.form-actions {
    width: 50%;
    text-align: center;
    margin-top: 1rem;
}

.submit-button
{
    display: inline-flex;
    align-items: center;
    padding: 10px;
    text-decoration: none;
    margin-top:0rem;
    margin-left:72%;
    width:50%;
}

/* Responsive Adjustments */
/* Responsive adjustments */
@media (max-width: 768px) {

    .form-container
    {
        width: 300px;
    }
    .view-form {
        flex-direction: column;
    }
    
    .form-column {
        flex: 100%;
    }

    .submit-button
    {
        margin-left:50%;
        width:80%;
    }
}

</style>

</head>
<body>
        <!-- Navbar -->
        <nav class="navbar">
        <div class="navbar-brand">
            <a href="../index.html">
                <img class="logo" src="../images/logo3.png" alt="Logo">
                <span>FinPack</span> 
            </a>
        </div>
        <ul class="nav-links">
            <li><a href="../dashboards/dashboard.php">
                <i class="fas fa-tachometer-alt"></i> Dashboard</a>
            </li>
            <li>
                <a href="#">
                    <i class="fas fa-user-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['username']); ?>
                </a>
            </li>
            <li>
                <a href="../logout.php" style="color:rgb(235, 71, 53);">
                    <i class="fas fa-sign-out-alt"></i>
                    Logout
                </a>
            </li>
        </ul>
    </nav>
<div class="ledger-form">
<div class="form-container main-content">
        <div class="form-header">
            <h2>
                <i class="fas fa-eye"></i> View Ledger Details
            </h2>
            <a href="edit_ledger.php?ledger_id=<?= $ledger_id ?>" class="edit-link">
                <i class="fas fa-edit"></i> Edit
            </a>
        </div>

        <?php if (isset($_SESSION['error_message'])): ?>
                <div class='error-message'><i class='fas fa-exclamation-circle'></i> <?= htmlspecialchars($_SESSION['error_message']) ?></div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

        <div class="view-form">
            <!-- Left Column -->
            <div class="form-column">
                <div class="form-group">
                    <label><i class="fas fa-signature"></i> Ledger Name:</label>
                    <div class="view-field"><?= htmlspecialchars($ledger['ledger_name']) ?></div>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-hashtag"></i> Account Code:</label>
                    <div class="view-field"><?= htmlspecialchars($ledger['acc_code']) ?></div>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-layer-group"></i> Group:</label>
                    <div class="view-field"><?= htmlspecialchars($ledger['group_name']) ?></div>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-tag"></i> Account Type:</label>
                    <div class="view-field"><?= htmlspecialchars($ledger['acc_type']) ?></div>
                </div>
            </div>
            
            <!-- Right Column -->
            <div class="form-column">
                <div class="form-group">
                    <label><i class="fas <?= $book_icon ?> book-type-icon"></i> Book Type:</label>
                    <div class="view-field"><?= htmlspecialchars($ledger['book_type']) ?></div>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-balance-scale"></i> Opening Balance:</label>
                    <div class="view-field balance-value"><?= htmlspecialchars($ledger['opening_balance']) ?></div>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-balance-scale"></i> Current Balance:</label>
                    <div class="view-field balance-value"><?= $formatted_balance ?></div>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-exchange-alt"></i> Balance Type:</label>
                    <div class="view-field">
                        <?= htmlspecialchars($ledger['debit_credit']) ?>
                        <i class="fas <?= ($ledger['debit_credit'] == 'Debit') ? 'fa-arrow-up text-success' : 'fa-arrow-down text-danger' ?> ml-2"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="form-actions">
                    <a href="search_ledger.php" class="submit-button">
                        <i class="fas fa-arrow-left"></i> Back to Ledgers
                    </a>
        </div>
</div>
    </div>
</body>
</html>