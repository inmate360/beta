<?php
/**
 * Reusable Navigation Dropdown Component
 * Designed to be included in index.php, contact.php, and beta_access.php
 */
?>
<style>
    /* Dropdown Menu Styles */
    .dropdown {
        position: relative;
        display: inline-block;
        margin-bottom: 1rem; /* Space below the dropdown */
    }

    .dropdown-btn {
        background: rgba(255, 255, 255, 0.1);
        color: #ffffff;
        padding: 0.75rem 1.5rem;
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 8px;
        cursor: pointer;
        font-size: 0.9rem;
        font-weight: 600;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        backdrop-filter: blur(10px);
    }

    .dropdown-btn:hover {
        background: rgba(255, 255, 255, 0.2);
        border-color: rgba(255, 255, 255, 0.4);
    }

    .dropdown-content {
        display: none;
        position: absolute;
        background: rgba(10, 10, 15, 0.9);
        backdrop-filter: blur(20px);
        min-width: 180px;
        box-shadow: 0 8px 16px 0 rgba(0,0,0,0.5);
        z-index: 20; /* Higher than glass-card */
        border-radius: 8px;
        border: 1px solid rgba(255, 255, 255, 0.1);
        padding: 0.5rem 0;
        margin-top: 0.5rem;
        right: 0; /* Align to the right of the button */
    }

    .dropdown-content a {
        color: rgba(255, 255, 255, 0.8);
        padding: 12px 16px;
        text-decoration: none;
        display: block;
        font-size: 0.9rem;
        font-weight: 500;
        transition: background-color 0.2s;
    }

    .dropdown-content a:hover {
        background-color: rgba(255, 255, 255, 0.1);
        color: #ffffff;
    }

    .dropdown:hover .dropdown-content {
        display: block;
    }
</style>

<div class="dropdown">
    <button class="dropdown-btn">
        Menu ☰
    </button>
    <div class="dropdown-content">
        <a href="index.php">Home / Dashboard</a>
        <a href="beta_access.php">Beta Access</a>
        <a href="contact.php">Contact & About</a>
        <a href="court_search.php">Court Search</a>
        <a href="inmate_view.php">Inmate View</a>
    </div>
</div>
