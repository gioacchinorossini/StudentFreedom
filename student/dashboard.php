<?php
require_once '../includes/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

// Get unread notification count
$stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$_SESSION['user_id']]);
$unread_count = $stmt->fetchColumn();

// Get user data from session
$user_id = $_SESSION['user_id'];
$student_id = $_SESSION['student_id'];
$first_name = $_SESSION['first_name'];
$last_name = $_SESSION['last_name'];
$email = $_SESSION['email'];

// Get search and filter parameters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$category_id = isset($_GET['category_id']) ? $_GET['category_id'] : '';
$selected_category = isset($_GET['category']) ? $_GET['category'] : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'newest';

// Get all categories for the dropdown
$categories = $conn->query("SELECT * FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Build query
$query = "SELECT p.*, u.username, c.name as category_name, c.color,
          (SELECT COUNT(*) FROM likes WHERE post_id = p.id) as like_count,
          (SELECT COUNT(*) FROM dislikes WHERE post_id = p.id) as dislike_count,
          (SELECT COUNT(*) FROM comments WHERE post_id = p.id) as comment_count,
          (SELECT COUNT(*) FROM likes WHERE post_id = p.id AND user_id = :user_id) as user_liked,
          (SELECT COUNT(*) FROM dislikes WHERE post_id = p.id AND user_id = :user_id) as user_disliked,
          p.is_anonymous
          FROM posts p 
          JOIN users u ON p.user_id = u.id 
          JOIN categories c ON p.category_id = c.id 
          WHERE 1=1";

if (!empty($search)) {
    $query .= " AND (p.title LIKE :search OR p.content LIKE :search)";
}

if (!empty($selected_category)) {
    $query .= " AND p.category_id = :category_id";
}

// Add sorting based on the sort parameter
switch ($sort) {
    case 'oldest':
        $query .= " ORDER BY p.created_at ASC";
        break;
    case 'most_likes':
        $query .= " ORDER BY like_count DESC, p.created_at DESC";
        break;
    case 'most_comments':
        $query .= " ORDER BY comment_count DESC, p.created_at DESC";
        break;
    case 'trending':
        // Trending is based on a combination of likes, comments, and recency
        $query .= " ORDER BY (like_count * 2 + comment_count) DESC, p.created_at DESC";
        break;
    default: // newest
        $query .= " ORDER BY p.created_at DESC";
}

$stmt = $conn->prepare($query);

// Bind all parameters
$stmt->bindParam(':user_id', $_SESSION['user_id']);

if (!empty($search)) {
    $searchParam = "%$search%";
    $stmt->bindParam(':search', $searchParam);
}

if (!empty($selected_category)) {
    $stmt->bindParam(':category_id', $selected_category);
}

$stmt->execute();
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - Freedom Wall</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Loading Animation Styles */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, var(--primary-red), var(--secondary-gold));
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            opacity: 1;
            transition: opacity 0.5s ease-out;
        }
        
        .loading-overlay.fade-out {
            opacity: 0;
        }
        
        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 5px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
            margin-bottom: 20px;
        }
        
        .loading-text {
            color: #fff;
            font-size: 1.2rem;
            font-weight: 500;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Fallback for loading overlay - will hide after 5 seconds if JavaScript fails */
        @media (prefers-reduced-motion: no-preference) {
            .loading-overlay {
                animation: hideLoading 5s forwards;
            }
            
            @keyframes hideLoading {
                0%, 90% { opacity: 1; }
                100% { opacity: 0; }
            }
        }
        
        /* Post Animation Styles */
        .post-card {
            break-inside: avoid;
            margin-bottom: 15px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.5s ease;
            display: flex;
            flex-direction: column;
            height: auto;
            min-height: fit-content;
            border-left: 4px solid var(--category-color);
            cursor: pointer;
            width: 100%;
            max-width: 100%;
            opacity: 0;
            animation: fadeInUp 0.6s ease forwards;
            animation-delay: calc(var(--animation-order) * 0.1s);
            position: relative;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .post-card:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: 0 8px 15px rgba(0,0,0,0.15);
        }

        .post-content {
            padding: 12px;
            flex-grow: 1;
            overflow: visible;
            height: auto;
            min-height: fit-content;
            transition: all 0.3s ease;
        }

        .post-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            position: relative;
            padding-right: 0; /* Remove padding-right */
        }

        .post-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0;
            color: var(--category-color);
            word-wrap: break-word;
            transition: color 0.3s ease;
            flex: 1;
            margin-right: 10px; /* Add margin to create space between title and badge */
        }

        .post-card:hover .post-title {
            color: var(--primary-red);
        }

        .post-text {
            color: #666;
            font-size: 0.85rem;
            line-height: 1.4;
            margin-bottom: 12px;
            word-wrap: break-word;
            white-space: pre-wrap;
            overflow: visible;
            height: auto;
            min-height: fit-content;
            transition: color 0.3s ease;
        }

        .post-card:hover .post-text {
            color: #333;
        }

        .post-meta {
            display: flex;
            justify-content: space-between;
            color: #999;
            font-size: 0.75rem;
            margin-bottom: 8px;
            transition: color 0.3s ease;
        }

        .post-card:hover .post-meta {
            color: #666;
        }

        .post-actions {
            display: flex;
            gap: 12px;
            margin-top: 8px;
            transition: transform 0.3s ease;
        }

        .post-card:hover .post-actions {
            transform: translateY(-2px);
        }

        .action-btn {
            color: var(--category-color);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 4px;
            transition: all 0.3s ease;
            opacity: 0.7;
            font-size: 0.8rem;
            transform: scale(1);
        }

        .action-btn:hover {
            color: var(--category-color);
            opacity: 1;
            transform: scale(1.1);
        }

        .action-btn.active {
            color: var(--category-color);
            opacity: 1;
            font-weight: bold;
            animation: pulse 1.5s infinite;
        }

        @keyframes pulse {
            0% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.1);
            }
            100% {
                transform: scale(1);
            }
        }

        .action-btn.active i.fa-heart {
            color: #ff4444;
            animation: heartbeat 1.5s infinite;
        }

        @keyframes heartbeat {
            0% {
                transform: scale(1);
            }
            14% {
                transform: scale(1.3);
            }
            28% {
                transform: scale(1);
            }
            42% {
                transform: scale(1.3);
            }
            70% {
                transform: scale(1);
            }
        }

        .action-btn.active i.fa-thumbs-down {
            color: #ff4444;
            animation: shake 0.5s infinite;
        }

        @keyframes shake {
            0%, 100% {
                transform: rotate(0deg);
            }
            25% {
                transform: rotate(-10deg);
            }
            75% {
                transform: rotate(10deg);
            }
        }

        .category-badge {
            background: var(--category-color);
            color: white;
            padding: 4px 8px;
            border-radius: 16px;
            font-size: 0.75rem;
            font-weight: 500;
            opacity: 0.9;
            transition: all 0.3s ease;
            position: relative; /* Change from absolute to relative */
            display: flex;
            align-items: center;
            gap: 5px;
            flex-shrink: 0; /* Prevent badge from shrinking */
        }

        .category-badge i {
            font-size: 0.8rem;
        }

        .post-card:hover .category-badge {
            opacity: 1;
            transform: scale(1.05);
        }

        .fab-container {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 1000;
        }

        .fab-button {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--primary-red);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
            text-decoration: none;
            cursor: pointer;
            border: none;
        }

        .fab-button:hover {
            transform: scale(1.1);
            background: var(--secondary-red);
            color: white;
        }

        .fab-menu {
            position: absolute;
            bottom: 70px;
            right: 0;
            display: flex;
            flex-direction: column;
            gap: 10px;
            opacity: 0;
            transform: translateY(20px);
            pointer-events: none;
            transition: all 0.3s ease;
        }

        .fab-menu.active {
            opacity: 1;
            transform: translateY(0);
            pointer-events: all;
        }

        .fab-item {
            background: white;
            color: var(--primary-red);
            padding: 12px 20px;
            border-radius: 25px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            white-space: nowrap;
        }

        .fab-item:hover {
            background: var(--primary-red);
            color: white;
            transform: translateX(-5px);
        }

        .fab-item i {
            font-size: 18px;
        }

        .fab-item span {
            font-weight: 500;
        }

        .fab-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
        }

        .fab-overlay.active {
            opacity: 1;
            pointer-events: all;
        }

        :root {
            --primary-red: #E74C3C;
            --secondary-red: #C0392B;
            --secondary-gold: #D4AF37;
            --light-gold: #FFD700;
            --dark-grey: #2C3E50;
            --light-grey: #ECF0F1;
            --white: #FFFFFF;
            --sidebar-width: 70px;
            --sidebar-expanded-width: 250px;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--light-grey);
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }

        /* Sidebar Styles */
        .sidebar {
                position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: var(--sidebar-width);
            background-color: var(--dark-grey);
            color: var(--white);
            padding: 20px 10px;
            z-index: 1000;
            transition: all 0.3s ease;
            overflow-x: hidden;
            display: flex;
            flex-direction: column;
        }

        .sidebar:hover {
            width: var(--sidebar-expanded-width);
        }

        .sidebar-header {
            padding: 20px 0;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 20px;
            white-space: nowrap;
            overflow: hidden;
        }

        .sidebar-nav {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .sidebar-footer {
            padding-top: 20px;
            border-top: 1px solid rgba(255,255,255,0.1);
            margin-top: auto;
        }

        .sidebar-header h1 {
            font-size: 1.5rem;
            margin: 0;
            color: var(--secondary-gold);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .sidebar:hover .sidebar-header h1 {
            opacity: 1;
        }

        .nav-link {
            color: var(--white);
            padding: 12px 15px;
            margin: 5px 0;
            border-radius: 8px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 15px;
            white-space: nowrap;
            overflow: hidden;
        }

        .nav-link span {
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .sidebar:hover .nav-link span {
            opacity: 1;
        }

        .nav-link:hover {
            background-color: rgba(255,255,255,0.1);
            color: var(--secondary-gold);
        }

        .nav-link.active {
            background-color: var(--secondary-gold);
            color: var(--white);
        }

        .nav-link i {
            width: 20px;
            text-align: center;
            font-size: 1.2rem;
        }

        /* Main Content Styles */
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 20px;
            min-height: 100vh;
            transition: all 0.3s ease;
        }

        .sidebar:hover + .main-content {
            margin-left: var(--sidebar-expanded-width);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
                width: var(--sidebar-expanded-width);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .toggle-sidebar {
                display: block;
            }
        }

        /* Existing Styles */
        .welcome-section {
            background: linear-gradient(135deg, var(--primary-red), var(--secondary-gold));
            color: var(--white);
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .welcome-section h2 {
            margin: 0;
            font-size: 1.8rem;
        }

        .welcome-section p {
            margin: 10px 0 0;
            opacity: 0.9;
        }

        .post-grid {
            columns: 4 250px;
            column-gap: 20px;
            padding: 20px;
        }

        @media (max-width: 768px) {
            .post-grid {
                columns: 2 150px;
                column-gap: 15px;
                padding: 15px;
            }
        }

        @media (max-width: 576px) {
            .post-grid {
                columns: 2 140px;
                column-gap: 10px;
                padding: 10px;
            }
        }

        .post-card {
            cursor: pointer;
        }

        .action-btn-expanded {
            color: var(--category-color);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 8px 15px;
            border-radius: 20px;
            background: rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            margin-right: 10px;
        }

        .action-btn-expanded:hover {
            background: rgba(0,0,0,0.1);
            color: var(--category-color);
        }

        .action-btn-expanded.active {
            background: var(--category-color);
            color: white;
        }

        .action-btn-expanded.active i.fa-heart {
            color: #ff4444;
        }

        .action-btn-expanded.active i.fa-thumbs-down {
            color: #ff4444;
        }

        .post-content-expanded {
            font-size: 1.1rem;
            line-height: 1.6;
            color: #333;
            white-space: pre-wrap;
            word-wrap: break-word;
            overflow-wrap: break-word;
            max-width: 100%;
        }

        .modal-body {
            max-height: 80vh;
            overflow-y: auto;
            padding: 20px;
        }

        .modal-lg {
            max-width: 800px;
            margin: 1.75rem auto;
        }

        .modal-content {
            border-radius: 15px;
            border: none;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .modal-header h5 {
            margin-bottom: 0.25rem;
        }

        .comment-count {
            display: block;
            margin-top: 0.25rem;
        }

        .post-meta {
            display: flex;
            justify-content: space-between;
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
        }

        .post-actions-expanded {
            display: flex;
            gap: 15px;
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid rgba(0,0,0,0.1);
        }

        .comments-section {
            border-top: 1px solid rgba(0,0,0,0.1);
            padding-top: 1.5rem;
        }

        .comments-title {
            color: var(--category-color);
            font-weight: 600;
        }

        .comment-form {
            margin-top: 1rem;
        }

        .comment-form .input-group {
            background: rgba(0,0,0,0.05);
            border-radius: 25px;
            padding: 5px;
        }

        .comment-form input {
            border: none;
            background: transparent;
            padding: 8px 15px;
        }

        .comment-form input:focus {
            box-shadow: none;
        }

        .comment-form button {
            border-radius: 20px;
            padding: 8px 20px;
            background: var(--category-color);
            border: none;
        }

        .comment-form button:hover {
            opacity: 0.9;
        }

        .action-btn.text-muted {
            cursor: default;
        }

        .search-filter-container {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .search-box {
            flex: 1;
            min-width: 200px;
            position: relative;
        }

        .search-box input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.5rem;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .search-box i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
        }

        .filter-options {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .filter-options select {
            padding: 0.75rem 1rem;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 0.9rem;
            min-width: 150px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .trending-btn {
            position: relative;
            padding: 0.75rem 1rem;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 0.9rem;
            background: #f8f9fa;
            color: #666;
            cursor: not-allowed;
            transition: all 0.3s ease;
        }

        .trending-btn:hover .trending-tooltip {
            display: block;
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        .trending-tooltip {
            display: none;
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%) translateY(10px);
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            padding: 1rem;
            min-width: 200px;
            max-width: 300px;
            z-index: 1000;
            opacity: 0;
            transition: all 0.3s ease;
        }

        .trending-category {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid #eee;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-left: 10px;
        }

        .trending-category:last-child {
            border-bottom: none;
        }
        
        .trending-category-text {
            color: var(--category-color);
            font-weight: 500;
            margin-left: 10px;
            position: absolute; /* Position absolutely */
            right: 10px; /* Position on the right side */
            top: 0; /* Align with the top */
        }
        
        /* Category badge adjustment */
        .category-badge {
            background: var(--category-color);
            color: white;
            padding: 4px 8px;
            border-radius: 16px;
            font-size: 0.75rem;
            font-weight: 500;
            opacity: 0.9;
            transition: all 0.3s ease;
            margin-left: 10px;
            margin-right: 0; /* Remove right margin */
            position: absolute; /* Position absolutely */
            right: 10px; /* Position on the right side */
            top: 0; /* Align with the top */
        }

        .post-card:hover .category-badge {
            opacity: 1;
            transform: scale(1.05);
        }
        
        .fab-container {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 1000;
        }

        .fab-button {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--primary-red);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
            text-decoration: none;
            cursor: pointer;
            border: none;
        }

        .fab-button:hover {
            transform: scale(1.1);
            background: var(--secondary-red);
            color: white;
        }

        .fab-menu {
            position: absolute;
            bottom: 70px;
            right: 0;
            display: flex;
            flex-direction: column;
            gap: 10px;
            opacity: 0;
            transform: translateY(20px);
            pointer-events: none;
            transition: all 0.3s ease;
        }

        .fab-menu.active {
            opacity: 1;
            transform: translateY(0);
            pointer-events: all;
        }

        .fab-item {
            background: white;
            color: var(--primary-red);
            padding: 12px 20px;
            border-radius: 25px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            white-space: nowrap;
        }

        .fab-item:hover {
            background: var(--primary-red);
            color: white;
            transform: translateX(-5px);
        }

        .fab-item i {
            font-size: 18px;
        }

        .fab-item span {
            font-weight: 500;
        }

        .fab-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
        }

        .fab-overlay.active {
            opacity: 1;
            pointer-events: all;
        }
        
        /* Trending Section Styles */
        .trending-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 20px;
            color: var(--dark-grey);
            position: relative;
            padding-bottom: 10px;
        }

        .section-title:after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 50px;
            height: 3px;
            background: linear-gradient(90deg, var(--primary-red), var(--secondary-gold));
            border-radius: 3px;
        }

        .trending-posts {
            display: flex;
            flex-direction: row;
            gap: 15px;
            overflow-x: auto;
            padding-bottom: 10px;
            scrollbar-width: thin;
            scrollbar-color: var(--primary-red) #f0f0f0;
        }

        .trending-posts::-webkit-scrollbar {
            height: 6px;
        }

        .trending-posts::-webkit-scrollbar-track {
            background: #f0f0f0;
            border-radius: 3px;
        }

        .trending-posts::-webkit-scrollbar-thumb {
            background: var(--primary-red);
            border-radius: 3px;
        }

        .trending-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.5s ease;
            display: flex;
            flex-direction: column;
            height: auto;
            min-height: fit-content;
            border-left: 4px solid var(--category-color);
            cursor: pointer;
            width: 100%;
            max-width: 100%;
            opacity: 0;
            animation: fadeInUp 0.6s ease forwards;
            animation-delay: calc(var(--animation-order, 0) * 0.1s);
            margin-bottom: 15px;
            position: relative;
        }
        
        .trending-card:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: 0 8px 15px rgba(0,0,0,0.15);
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .trending-rank {
            position: absolute;
            top: 0;
            left: 0;
            background: var(--category-color);
            color: white;
            font-weight: bold;
            padding: 5px 10px;
            border-bottom-right-radius: 8px;
            font-size: 0.9rem;
            z-index: 2;
        }

        .trending-content {
            padding: 15px;
            flex-grow: 1;
            padding-top: 40px; /* Add padding to prevent overlap with rank badge */
        }

        .trending-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--dark-grey);
            padding-right: 10px; /* Add padding to prevent text from getting too close to the edge */
        }

        .trending-text {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 10px;
            line-height: 1.4;
        }

        .trending-meta {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 0.8rem;
            color: #888;
        }

        .trending-author {
            font-weight: 500;
        }

        .trending-stats {
            display: flex;
            gap: 15px;
            font-size: 0.8rem;
        }

        .trending-likes, .trending-comments, .trending-engagement {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .trending-likes i {
            color: #e74c3c;
        }

        .trending-comments i {
            color: #3498db;
        }

        .trending-engagement i {
            color: #f39c12;
        }

        .no-trending {
            text-align: center;
            padding: 30px;
            color: #888;
            font-style: italic;
        }

        @media (max-width: 768px) {
            .trending-posts {
                flex-direction: row;
                overflow-x: auto;
                padding-bottom: 15px;
            }
            
            .trending-card {
                min-width: 250px;
                max-width: 250px;
            }
        }

        .trending-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .trending-filter {
            width: 150px;
        }

        .trending-filter select {
            border-radius: 20px;
            border: 1px solid #e0e0e0;
            padding: 5px 10px;
            font-size: 0.9rem;
            background-color: white;
            cursor: pointer;
        }

        .loading-trending, .error-trending {
            text-align: center;
            padding: 20px;
            color: var(--dark-grey);
            font-style: italic;
        }

        .error-trending {
            color: var(--secondary-red);
        }

        /* Trending Card Animation Styles */
        .trending-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.5s ease;
            display: flex;
            flex-direction: column;
            height: auto;
            min-height: fit-content;
            border-left: 4px solid var(--category-color);
            cursor: pointer;
            width: 100%;
            max-width: 100%;
            opacity: 0;
            animation: fadeInUp 0.6s ease forwards;
            animation-delay: calc(var(--animation-order, 0) * 0.1s);
            margin-bottom: 15px;
            position: relative;
        }
        
        .trending-card:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: 0 8px 15px rgba(0,0,0,0.15);
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Modal Animation Styles */
        .modal.fade .modal-dialog {
            transform: scale(0.8);
            transition: transform 0.3s ease-out;
        }
        
        .modal.show .modal-dialog {
            transform: scale(1);
        }
        
        .modal-content {
            opacity: 0;
            animation: fadeIn 0.3s ease forwards;
            animation-delay: 0.1s;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }
        
        .modal-body {
            opacity: 0;
            animation: slideInUp 0.4s ease forwards;
            animation-delay: 0.2s;
        }
        
        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .comments-section {
            opacity: 0;
            animation: slideInUp 0.4s ease forwards;
            animation-delay: 0.3s;
        }

        /* Your Post Badge */
        .your-post-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: linear-gradient(135deg, var(--primary-red), var(--secondary-gold));
            color: white;
            font-size: 0.7rem;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 12px;
            z-index: 2;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            animation: pulse 2s infinite;
        }
        
        /* Your Post Badge for regular post cards */
        .post-card .your-post-badge {
            top: 10px;
            right: 10px;
        }
        
        /* Your Post Badge for trending cards */
        .trending-card .your-post-badge {
            top: 10px;
            right: 10px;
        }
        
        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(231, 76, 60, 0.4);
            }
            70% {
                box-shadow: 0 0 0 5px rgba(231, 76, 60, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(231, 76, 60, 0);
            }
        }

        .trending-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            color: white;
            font-size: 0.8rem;
            font-weight: 500;
            max-width: 150px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .trending-count {
            color: #666;
            font-size: 0.8rem;
            margin-left: 0.5rem;
        }

        .filter-btn {
            padding: 0.75rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-red), var(--secondary-gold));
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .filter-btn i {
            font-size: 1rem;
        }

        @media (max-width: 768px) {
            .search-filter-container {
                flex-direction: column;
            }
            
            .filter-options {
                flex-direction: column;
            }
            
            .filter-options select,
            .trending-btn {
                width: 100%;
            }

            .trending-tooltip {
                left: 0;
                transform: translateY(10px);
                width: 100%;
                max-width: none;
            }

            .trending-btn:hover .trending-tooltip {
                transform: translateY(0);
            }
        }

        /* Category badge positioning */
        .category-badge {
            background: var(--category-color);
            color: white;
            padding: 4px 8px;
            border-radius: 16px;
            font-size: 0.75rem;
            font-weight: 500;
            opacity: 0.9;
            transition: all 0.3s ease;
            position: relative; /* Change from absolute to relative */
            display: flex;
            align-items: center;
            gap: 5px;
            flex-shrink: 0; /* Prevent badge from shrinking */
        }

        .category-badge i {
            font-size: 0.8rem;
        }

        .post-card:hover .category-badge {
            opacity: 1;
            transform: scale(1.05);
        }

        /* Your Post badge positioning */
        .your-post-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: linear-gradient(135deg, var(--primary-red), var(--secondary-gold));
            color: white;
            padding: 4px 8px;
            border-radius: 16px;
            font-size: 0.75rem;
            font-weight: 500;
            z-index: 2;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            animation: pulse 2s infinite;
        }

        /* Your Post Badge for regular post cards */
        .post-card .your-post-badge {
            top: 10px;
            right: 10px;
        }
        
        /* Your Post Badge for trending cards */
        .trending-card .your-post-badge {
            top: 10px;
            right: 10px;
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.4);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(220, 53, 69, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(220, 53, 69, 0);
            }
        }

        /* Your Post Tab Styling */
        .your-post-tab {
            display: none;
        }
    </style>
</head>
<body class="<?php echo isLoggedIn() ? 'logged-in' : ''; ?>">
    <!-- Loading Overlay - Temporarily disabled for troubleshooting -->
    <div class="loading-overlay" style="display: none;">
        <div class="loading-spinner"></div>
        <div class="loading-text">Loading Freedom Wall...</div>
    </div>

    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <h1>Be Heard</h1>
        </div>
        <nav class="nav flex-column sidebar-nav">
            <a class="nav-link active" href="dashboard.php" title="Dashboard">
                <i class="fas fa-home"></i>
                <span>Freedom Wall</span>
            </a>
            <a class="nav-link" href="profile.php" title="Profile">
                <i class="fas fa-user"></i>
                <span>Profile</span>
            </a>
            <a class="nav-link" href="my_posts.php" title="My Posts">
                <i class="fas fa-file-alt"></i>
                <span>My Posts</span>
            </a>
            <a class="nav-link" href="notifications.php" title="Notifications">
                <i class="fas fa-bell"></i>
                <span>Notifications</span>
            </a>
            <a class="nav-link" href="settings.php" title="Settings">
                <i class="fas fa-cog"></i>
                <span>Settings</span>
            </a>
        </nav>
        <div class="sidebar-footer">
            <a class="nav-link" href="logout.php" title="Logout">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
            </div>
        </div>

    <!-- Toggle Sidebar Button (Mobile) -->
    <button class="toggle-sidebar">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Welcome Section -->
        <div class="welcome-section">
            <h2>Welcome, <?php echo htmlspecialchars($first_name . ' ' . $last_name); ?>!</h2>
            <p>Share your thoughts and connect with other students.</p>
                </div>

        <!-- Trending Section -->
        <div class="trending-section">
            <div class="trending-header">
                <h3>Trending Expressions</h3>
                <div class="trending-filter">
                    <select id="trendingTimeFilter" class="form-select">
                        <option value="present">Present</option>
                        <option value="today">Today</option>
                        <option value="week">This Week</option>
                        <option value="month">This Month</option>
                    </select>
                </div>
            </div>
            <div class="trending-posts" id="trendingPostsContainer">
                <?php
                // Get trending posts (most likes and comments) based on time filter
                $time_filter = isset($_GET['trending_time']) ? $_GET['trending_time'] : 'present';
                
                $time_condition = "";
                if ($time_filter == 'present') {
                    $time_condition = ""; // No time restriction for real-time trending
                } else if ($time_filter == 'today') {
                    $time_condition = "AND p.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
                } else if ($time_filter == 'week') {
                    $time_condition = "AND p.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                } else if ($time_filter == 'month') {
                    $time_condition = "AND p.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
                }
                
                $trending_query = "SELECT p.*, u.username, c.name as category_name, c.color,
                                 (SELECT COUNT(*) FROM likes WHERE post_id = p.id) as like_count,
                                 (SELECT COUNT(*) FROM comments WHERE post_id = p.id) as comment_count
                                 FROM posts p 
                                 JOIN users u ON p.user_id = u.id 
                                 JOIN categories c ON p.category_id = c.id 
                                 WHERE 1=1 $time_condition";
                
                // No category filter for any time period - show all categories
                // This allows the "Present" filter to show trending posts from all categories
                
                $trending_query .= " ORDER BY (like_count + comment_count) DESC LIMIT 5";
                
                try {
                    $trending_stmt = $conn->prepare($trending_query);
                    $trending_stmt->execute();
                    $trending_posts = $trending_stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Debug output
                    error_log("Time filter: " . $time_filter);
                    error_log("Query: " . $trending_query);
                    error_log("Number of posts found: " . count($trending_posts));
                    
                    if (count($trending_posts) > 0) {
                        foreach ($trending_posts as $index => $post) {
                            $like_count = $post['like_count'];
                            $comment_count = $post['comment_count'];
                            $total_engagement = $like_count + $comment_count;
                            $category_color = $post['color'];
                            ?>
                            <div class="trending-card" style="--category-color: <?php echo $category_color; ?>" data-post-id="<?php echo $post['id']; ?>">
                                <?php if ($post['user_id'] == $_SESSION['user_id']): ?>
                                    <div class="your-post-tab">
                                        <i class="fas fa-user"></i>
                                    </div>
                                <?php endif; ?>
                                <div class="trending-rank">#<?php echo $index + 1; ?></div>
                                <div class="trending-content">
                                    <h4 class="trending-title"><?php echo htmlspecialchars($post['title']); ?></h4>
                                    <p class="trending-text"><?php echo substr(htmlspecialchars($post['content']), 0, 100) . (strlen($post['content']) > 100 ? '...' : ''); ?></p>
                                    <div class="trending-meta">
                                        <span class="trending-author"><?php echo $post['is_anonymous'] ? 'Anonymous' : htmlspecialchars($post['username']); ?></span>
                                        <span class="trending-category">
                                            <?php echo htmlspecialchars($post['category_name']); ?>
                                            <?php if ($post['user_id'] == $_SESSION['user_id']): ?>
                                                <i class="fas fa-user"></i>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <div class="trending-stats">
                                        <span class="trending-likes"><i class="fas fa-heart"></i> <?php echo $like_count; ?></span>
                                        <span class="trending-comments"><i class="fas fa-comment"></i> <?php echo $comment_count; ?></span>
                                        <span class="trending-engagement"><i class="fas fa-fire"></i> <?php echo $total_engagement; ?></span>
                                    </div>
                                </div>
                            </div>
                            <?php
                        }
                    } else {
                        echo '<div class="no-trending">No trending posts for this time period.</div>';
                    }
                } catch (PDOException $e) {
                    echo '<div class="error-trending">Error loading trending posts: ' . $e->getMessage() . '</div>';
                }
                ?>
            </div>
                </div>

        <!-- Search and Filter Section -->
        <div class="search-filter-container">
            <div class="search-box">
                <input type="text" id="searchInput" placeholder="Search posts..." value="<?php echo htmlspecialchars($search); ?>">
                <i class="fas fa-search"></i>
                </div>
            <div class="filter-options">
                <select id="categoryFilter">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?php echo $category['id']; ?>" <?php echo $selected_category == $category['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($category['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select id="sortFilter">
                    <option value="newest" <?php echo $sort == 'newest' ? 'selected' : ''; ?>>Newest</option>
                    <option value="oldest" <?php echo $sort == 'oldest' ? 'selected' : ''; ?>>Oldest</option>
                    <option value="most_likes" <?php echo $sort == 'most_likes' ? 'selected' : ''; ?>>Most Likes</option>
                    <option value="most_comments" <?php echo $sort == 'most_comments' ? 'selected' : ''; ?>>Most Comments</option>
                    <option value="trending" <?php echo $sort == 'trending' ? 'selected' : ''; ?>>Trending</option>
                </select>
                <button class="trending-btn" disabled>
                    <span class="trending-text">Trending Categories</span>
                    <div class="trending-tooltip">
                        <?php
                        // Get trending categories based on post count
                        $trending_stmt = $conn->query("
                            SELECT c.id, c.name, c.color, COUNT(p.id) as post_count 
                            FROM categories c 
                            LEFT JOIN posts p ON c.id = p.category_id 
                            GROUP BY c.id 
                            ORDER BY post_count DESC 
                            LIMIT 5
                        ");
                        $trending_categories = $trending_stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($trending_categories as $trending): ?>
                            <div class="trending-category">
                                <span class="trending-badge" style="background-color: <?php echo $trending['color']; ?>">
                                    <?php echo htmlspecialchars($trending['name']); ?>
                                </span>
                                <span class="trending-count"><?php echo $trending['post_count']; ?> posts</span>
                    </div>
                        <?php endforeach; ?>
                        </div>
                </button>
                <button class="filter-btn" id="applyFilter">
                    <i class="fas fa-filter"></i>
                    Apply Filter
                </button>
                    </div>
                </div>

                <!-- Posts Grid -->
        <div class="post-grid">
            <?php foreach ($posts as $post): ?>
                <div class="post-card" style="--category-color: <?php echo htmlspecialchars($post['color']); ?>" data-post-id="<?php echo $post['id']; ?>">
                        <?php if ($post['user_id'] == $_SESSION['user_id']): ?>
                            <div class="your-post-tab">
                                <i class="fas fa-user"></i>
                            </div>
                        <?php endif; ?>
                        <div class="post-content">
                        <div class="post-header">
                            <h5 class="post-title"><?php echo htmlspecialchars($post['title']); ?></h5>
                            <span class="category-badge">
                                <?php echo ucfirst(htmlspecialchars($post['category_name'])); ?>
                                <?php if ($post['user_id'] == $_SESSION['user_id']): ?>
                                    <i class="fas fa-user"></i>
                                <?php endif; ?>
                            </span>
                        </div>
                        <p class="post-text"><?php echo nl2br(htmlspecialchars($post['content'])); ?></p>
                        <div class="post-meta">
                            <span><?php echo $post['is_anonymous'] ? 'Anonymous' : htmlspecialchars($post['username']); ?></span>
                            <span><?php echo date('M d, Y h:i A', strtotime($post['created_at'])); ?></span>
                        </div>
                        <div class="post-actions">
                            <a href="#" class="action-btn <?php echo $post['user_liked'] ? 'active' : ''; ?>" onclick="handleReaction(event, <?php echo $post['id']; ?>, 'like')">
                                <i class="fas fa-heart"></i>
                                <span class="like-count"><?php echo $post['like_count']; ?></span>
                            </a>
                            <a href="#" class="action-btn <?php echo $post['user_disliked'] ? 'active' : ''; ?>" onclick="handleReaction(event, <?php echo $post['id']; ?>, 'dislike')">
                                <i class="fas fa-thumbs-down"></i>
                                <span class="dislike-count"><?php echo $post['dislike_count']; ?></span>
                            </a>
                            <span class="action-btn text-muted" style="opacity: 0.5;">
                                <i class="fas fa-comment"></i>
                                <span><?php echo $post['comment_count']; ?></span>
                            </span>
                        </div>
                    </div>
                        </div>
            <?php endforeach; ?>
                        </div>
                        </div>

    <!-- Floating Action Button with Menu -->
    <div class="fab-container">
        <div class="fab-overlay"></div>
        <div class="fab-menu">
            <a href="#" class="fab-item" data-bs-toggle="modal" data-bs-target="#createPostModal">
                <i class="fas fa-comment"></i>
                <span>Express</span>
            </a>
            <a href="create_complaint.php" class="fab-item">
                <i class="fas fa-exclamation-circle"></i>
                <span>Complaint</span>
            </a>
        </div>
        <button class="fab-button" id="fabButton">
            <i class="fas fa-plus"></i>
        </button>
                    </div>

    <!-- Create Post Modal -->
    <div class="modal fade" id="createPostModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Post</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                <div class="modal-body">
                    <form class="post-form">
                        <div class="mb-3">
                            <label class="form-label">Title</label>
                            <input type="text" class="form-control" name="title" required>
                        </div>
                        <div class="mb-3">
                            <label for="postCategory" class="form-label">Category</label>
                            <select class="form-select" id="postCategory" name="category_id" required>
                                <option value="">Select a category</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="postContent" class="form-label">Content</label>
                            <textarea class="form-control" id="postContent" name="content" rows="4" required></textarea>
                    </div>
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="anonymousPost" name="is_anonymous" checked>
                            <label class="form-check-label" for="anonymousPost">Anonymous Posting</label>
                            <div class="form-text text-muted">Your post will be displayed anonymously</div>
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Post</button>
                        </div>
                    </form>
                        </div>
                    </div>
                </div>
            </div>

    <!-- Post Expansion Modal -->
    <div class="modal fade" id="postExpansionModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title"></h5>
                        <small class="text-muted comment-count"></small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="post-meta mb-3">
                        <span class="post-author"></span>
                        <span class="post-date"></span>
                    </div>
                    <div class="post-content-expanded"></div>
                    <div class="post-actions-expanded mt-4">
                        <!-- This div will be filled with the post actions -->
                    </div>
                    
                    <!-- Comments Section -->
                    <div class="comments-section mt-4">
                        <h6 class="comments-title mb-3">Comments</h6>
                        <div class="comments-list"></div>
                        
                        <!-- Comment Form -->
                        <form class="comment-form mt-3">
                            <div class="input-group">
                                <input type="text" class="form-control" placeholder="Write a comment..." required>
                                <button class="btn btn-primary" type="submit">
                                    <i class="fas fa-paper-plane"></i>
                                </button>
                </div>
                        </form>
            </div>
        </div>
    </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const fabButton = document.getElementById('fabButton');
            const fabMenu = document.querySelector('.fab-menu');
            const fabOverlay = document.querySelector('.fab-overlay');
            const fabIcon = fabButton.querySelector('i');

            function toggleFab() {
                fabMenu.classList.toggle('active');
                fabOverlay.classList.toggle('active');
                fabIcon.classList.toggle('fa-plus');
                fabIcon.classList.toggle('fa-times');
            }

            fabButton.addEventListener('click', toggleFab);
            fabOverlay.addEventListener('click', toggleFab);

            // Close menu when clicking outside
            document.addEventListener('click', function(e) {
                if (!fabButton.contains(e.target) && !fabMenu.contains(e.target)) {
                    fabMenu.classList.remove('active');
                    fabOverlay.classList.remove('active');
                    fabIcon.classList.remove('fa-times');
                    fabIcon.classList.add('fa-plus');
                }
            });
        });

        // Mobile Sidebar Toggle
        document.querySelector('.toggle-sidebar').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 768) {
                if (!e.target.closest('.sidebar') && !e.target.closest('.toggle-sidebar')) {
                    document.querySelector('.sidebar').classList.remove('active');
                }
            }
        });

        // Post Expansion Modal
        document.addEventListener('DOMContentLoaded', function() {
            const postCards = document.querySelectorAll('.post-card');
            const postModal = new bootstrap.Modal(document.getElementById('postExpansionModal'));
            const modalTitle = document.querySelector('#postExpansionModal .modal-title');
            const modalAuthor = document.querySelector('#postExpansionModal .post-author');
            const modalDate = document.querySelector('#postExpansionModal .post-date');
            const modalContent = document.querySelector('#postExpansionModal .post-content-expanded');
            const modalActions = document.querySelector('#postExpansionModal .post-actions-expanded');

            postCards.forEach(card => {
                card.addEventListener('click', function(e) {
                    // Don't open modal when clicking on action buttons
                    if (e.target.closest('.action-btn')) {
                        return;
                    }
                    
                    const postId = this.dataset.postId;
                    const title = this.querySelector('.post-title').textContent;
                    const content = this.querySelector('.post-text').textContent;
                    const author = this.querySelector('.post-meta span:first-child').textContent;
                    const date = this.querySelector('.post-meta span:last-child').textContent;
                    const commentCount = this.querySelector('.action-btn:nth-child(3) span').textContent;
                    
                    modalTitle.textContent = title;
                    modalAuthor.textContent = author;
                    modalDate.textContent = date;
                    modalContent.textContent = content;
                    document.querySelector('.comment-count').textContent = `${commentCount} comments`;

                    // Clone the post actions
                    const actionsClone = this.querySelector('.post-actions').cloneNode(true);
                    modalActions.innerHTML = '';
                    modalActions.appendChild(actionsClone);
                    
                    // Store post ID in modal
                    document.getElementById('postExpansionModal').dataset.postId = postId;

                    // Load comments
                    loadComments(postId);
                    
                    postModal.show();
                });
            });
        });

        // Function to load comments
        function loadComments(postId) {
            const commentsList = document.querySelector('.comments-list');
            commentsList.innerHTML = '<p class="text-muted">Loading comments...</p>';
            
            fetch(`get_comments.php?post_id=${postId}`)
                .then(response => response.json())
                .then(comments => {
                    if (comments.error) {
                        commentsList.innerHTML = `<p class="text-danger">${comments.error}</p>`;
                        return;
                    }
                    
                    if (comments.length === 0) {
                        commentsList.innerHTML = '<p class="text-muted">No comments yet. Be the first to comment!</p>';
            } else {
                        commentsList.innerHTML = comments.map(comment => `
                            <div class="comment mb-3" data-comment-id="${comment.id}">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong>${comment.username}</strong>
                                        <small class="text-muted ms-2">${comment.created_at}</small>
                                    </div>
                                    ${comment.user_id == <?php echo $_SESSION['user_id']; ?> ? `
                                        <div class="comment-actions">
                                            <button class="btn btn-sm btn-link edit-comment" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-link text-danger delete-comment" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    ` : ''}
                                </div>
                                <p class="mb-0 comment-content">${comment.content}</p>
                                <div class="edit-form d-none mt-2">
                                    <div class="input-group">
                                        <input type="text" class="form-control form-control-sm" value="${comment.content}">
                                        <button class="btn btn-sm btn-primary save-edit">
                                            <i class="fas fa-check"></i>
                                        </button>
                                        <button class="btn btn-sm btn-secondary cancel-edit">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        `).join('');

                        // Add event listeners for edit and delete buttons
                        addCommentActionListeners();
                    }
                })
                .catch(error => {
                    commentsList.innerHTML = '<p class="text-danger">Error loading comments</p>';
                });
        }

        // Function to add event listeners for comment actions
        function addCommentActionListeners() {
            // Edit button listeners
            document.querySelectorAll('.edit-comment').forEach(button => {
            button.addEventListener('click', function() {
                    const commentDiv = this.closest('.comment');
                    commentDiv.querySelector('.comment-content').classList.add('d-none');
                    commentDiv.querySelector('.edit-form').classList.remove('d-none');
                });
            });

            // Cancel edit button listeners
            document.querySelectorAll('.cancel-edit').forEach(button => {
                button.addEventListener('click', function() {
                    const commentDiv = this.closest('.comment');
                    commentDiv.querySelector('.comment-content').classList.remove('d-none');
                    commentDiv.querySelector('.edit-form').classList.add('d-none');
                });
            });

            // Save edit button listeners
            document.querySelectorAll('.save-edit').forEach(button => {
                button.addEventListener('click', function() {
                    const commentDiv = this.closest('.comment');
                    const commentId = commentDiv.dataset.commentId;
                    const newContent = this.closest('.input-group').querySelector('input').value;

                    if (!newContent.trim()) {
                        alert('Comment cannot be empty');
                        return;
                    }

                    const formData = new FormData();
                    formData.append('comment_id', commentId);
                    formData.append('content', newContent);

                    fetch('edit_comment.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.error) {
                            alert(data.error);
                            return;
                        }

                        // Update the comment content
                        commentDiv.querySelector('.comment-content').textContent = data.content;
                        commentDiv.querySelector('.comment-content').classList.remove('d-none');
                        commentDiv.querySelector('.edit-form').classList.add('d-none');
                    })
                    .catch(error => {
                        alert('Error updating comment');
                    });
            });
        });

            // Delete button listeners
            document.querySelectorAll('.delete-comment').forEach(button => {
            button.addEventListener('click', function() {
                    if (!confirm('Are you sure you want to delete this comment?')) {
                        return;
                    }

                    const commentDiv = this.closest('.comment');
                    const commentId = commentDiv.dataset.commentId;

                    const formData = new FormData();
                    formData.append('comment_id', commentId);

                    fetch('delete_comment.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.error) {
                            alert(data.error);
                            return;
                        }

                        // Remove the comment from the DOM
                        commentDiv.remove();

                        // Update comment count
                        const commentCount = document.querySelector('.comment-count');
                        const currentCount = parseInt(commentCount.textContent);
                        commentCount.textContent = `${currentCount - 1} comments`;
                    })
                    .catch(error => {
                        alert('Error deleting comment');
            });
        });
            });
        }

        // Handle comment form submission
        document.querySelector('.comment-form').addEventListener('submit', function(e) {
            e.preventDefault();
            const input = this.querySelector('input');
            const postId = document.getElementById('postExpansionModal').dataset.postId;
            
            if (!postId) {
                alert('Error: Post ID not found');
                return;
            }

            const formData = new FormData();
            formData.append('post_id', postId);
            formData.append('content', input.value);

            fetch('post_comment.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    alert(data.error);
                    return;
                }

                // Add the new comment to the list
                const commentsList = document.querySelector('.comments-list');
                const commentHtml = `
                    <div class="comment mb-3">
                        <div class="d-flex justify-content-between">
                            <strong>${data.username}</strong>
                            <small class="text-muted">${data.created_at}</small>
                        </div>
                        <p class="mb-0">${data.content}</p>
                    </div>
                `;
                
                commentsList.insertAdjacentHTML('afterbegin', commentHtml);
                input.value = '';

                // Update comment count
                const commentCount = document.querySelector('.comment-count');
                const currentCount = parseInt(commentCount.textContent);
                commentCount.textContent = `${currentCount + 1} comments`;
            })
            .catch(error => {
                alert('Error posting comment');
            });
        });

        // Handle post form submission
        document.querySelector('.post-form').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.set('is_anonymous', document.getElementById('anonymousPost').checked ? '1' : '0');

            fetch('create_post.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    alert(data.error);
                    return;
                }

                // Add the new post to the grid
                const postGrid = document.querySelector('.post-grid');
                const postHtml = `
                    <div class="post-card" style="--category-color: ${data.color}" data-post-id="${data.id}">
                        <div class="post-content">
                            <div class="post-header">
                                <h5 class="post-title">${data.title}</h5>
                                <span class="category-badge">${data.category_name}</span>
                            </div>
                            <p class="post-text">${data.content}</p>
                            <div class="post-meta">
                                <span>${data.is_anonymous == 1 ? 'Anonymous' : data.username}</span>
                                <span>Just now</span>
                            </div>
                            <div class="post-actions">
                                <a href="#" class="action-btn ${data.user_liked ? 'active' : ''}" onclick="handleReaction(event, ${data.id}, 'like')">
                                    <i class="fas fa-heart"></i>
                                    <span class="like-count">0</span>
                                </a>
                                <a href="#" class="action-btn ${data.user_disliked ? 'active' : ''}" onclick="handleReaction(event, ${data.id}, 'dislike')">
                                    <i class="fas fa-thumbs-down"></i>
                                    <span class="dislike-count">0</span>
                                </a>
                                <span class="action-btn text-muted" style="opacity: 0.5;">
                                    <i class="fas fa-comment"></i>
                                    <span>0</span>
                                </span>
                            </div>
                        </div>
                    </div>
                `;
                
                postGrid.insertAdjacentHTML('afterbegin', postHtml);
                const postModal = bootstrap.Modal.getInstance(document.getElementById('createPostModal'));
                postModal.hide();
                this.reset();
            })
            .catch(error => {
                alert('Error creating post');
            });
        });

        document.getElementById('sortFilter').addEventListener('change', function() {
            const sort = this.value;
            const search = document.getElementById('searchInput').value;
            const category = document.getElementById('categoryFilter').value;
            
            // Use AJAX to fetch filtered posts without page reload
            fetchFilteredPosts(search, category, sort);
        });

        // Add hover effect for trending button
        const trendingBtn = document.querySelector('.trending-btn');
        trendingBtn.addEventListener('mouseenter', function() {
            this.style.backgroundColor = '#f0f0f0';
        });

        trendingBtn.addEventListener('mouseleave', function() {
            this.style.backgroundColor = '#f8f9fa';
        });

        // Add filter button functionality
        document.getElementById('applyFilter').addEventListener('click', function() {
            const search = document.getElementById('searchInput').value;
            const category = document.getElementById('categoryFilter').value;
            const sort = document.getElementById('sortFilter').value;
            
            // Use AJAX to fetch filtered posts without page reload
            fetchFilteredPosts(search, category, sort);
        });

        // Add enter key support for search
        document.getElementById('searchInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                document.getElementById('applyFilter').click();
            }
        });
        
        // Function to fetch filtered posts using AJAX
        function fetchFilteredPosts(search, category, sort) {
            // Show a small loading indicator in the post grid area
            const postGrid = document.querySelector('.post-grid');
            postGrid.innerHTML = '<div class="text-center p-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>';
            
            // Build the query string
            const queryParams = new URLSearchParams();
            if (search) queryParams.append('search', search);
            if (category) queryParams.append('category', category);
            if (sort) queryParams.append('sort', sort);
            
            // Update URL without reloading the page
            const newUrl = `${window.location.pathname}?${queryParams.toString()}`;
            window.history.pushState({}, '', newUrl);
            
            // Fetch filtered posts
            fetch(`get_filtered_posts.php?${queryParams.toString()}`)
                .then(response => response.text())
                .then(html => {
                    // Replace the post grid content
                    postGrid.innerHTML = html;
                    
                    // Reinitialize post card animations
                    const postCards = document.querySelectorAll('.post-card');
                    postCards.forEach((card, index) => {
                        card.style.animation = 'none';
                        card.offsetHeight; // Trigger reflow
                        card.style.animation = `fadeInUp 0.6s ease forwards`;
                        card.style.animationDelay = `${index * 0.03}s`;
                    });
                    
                    // Reinitialize click events for post cards
                    initializePostCardEvents();
                })
                .catch(error => {
                    console.error('Error fetching filtered posts:', error);
                    postGrid.innerHTML = '<div class="alert alert-danger">Error loading posts. Please try again.</div>';
                });
        }
        
        // Function to initialize post card events
        function initializePostCardEvents() {
            const postCards = document.querySelectorAll('.post-card');
            postCards.forEach(card => {
                card.addEventListener('click', function() {
                    const postId = this.dataset.postId;
                    // Find the corresponding post card and trigger its click event
                    const postCard = document.querySelector(`.post-card[data-post-id="${postId}"]`);
                    if (postCard) {
                        postCard.click();
                    } else {
                        // If the post is not in the current view, fetch it and show the modal
                        fetchPostDetails(postId);
                    }
                });
            });
        }

        // Loading animation
        document.addEventListener('DOMContentLoaded', function() {
            const loadingOverlay = document.querySelector('.loading-overlay');
            
            // Only show loading overlay if user is logged in (check for session)
            if (document.body.classList.contains('logged-in')) {
                // Check if this is the first time loading the dashboard (using sessionStorage)
                const hasSeenLoading = sessionStorage.getItem('hasSeenLoading');
                
                if (!hasSeenLoading) {
                    // First time loading - show the loading overlay
                    loadingOverlay.style.display = 'flex';
                    
                    // Hide loading overlay after a short delay
                    setTimeout(() => {
                        loadingOverlay.classList.add('fade-out');
                        setTimeout(() => {
                            loadingOverlay.style.display = 'none';
                            
                            // Mark that user has seen the loading screen
                            sessionStorage.setItem('hasSeenLoading', 'true');
                            
                            // Start trending card animations first
                            const trendingCards = document.querySelectorAll('.trending-card');
                            trendingCards.forEach((card, index) => {
                                // Reset animation by removing and re-adding the animation class
                                card.style.animation = 'none';
                                card.offsetHeight; // Trigger reflow
                                card.style.animation = `fadeInUp 0.6s ease forwards`;
                                // Smoother staggered animation with shorter delays
                                card.style.animationDelay = `${index * 0.03}s`;
                            });
                            
                            // Then start post animations after a short delay
                            setTimeout(() => {
                                const postCards = document.querySelectorAll('.post-card');
                                postCards.forEach((card, index) => {
                                    // Reset animation by removing and re-adding the animation class
                                    card.style.animation = 'none';
                                    card.offsetHeight; // Trigger reflow
                                    card.style.animation = `fadeInUp 0.6s ease forwards`;
                                    // Smoother staggered animation with shorter delays
                                    card.style.animationDelay = `${index * 0.03}s`;
                                });
                            }, 300); // Start post animations 300ms after trending cards
                        }, 500); // Wait for fade-out animation to complete
                    }, 1000); // Show loading screen for 1 second
                } else {
                    // Not first time - hide loading overlay immediately
                    loadingOverlay.style.display = 'none';
                    
                    // Still animate the cards without the loading screen
                    const trendingCards = document.querySelectorAll('.trending-card');
                    trendingCards.forEach((card, index) => {
                        card.style.animation = 'none';
                        card.offsetHeight; // Trigger reflow
                        card.style.animation = `fadeInUp 0.6s ease forwards`;
                        card.style.animationDelay = `${index * 0.03}s`;
                    });
                    
                    setTimeout(() => {
                        const postCards = document.querySelectorAll('.post-card');
                        postCards.forEach((card, index) => {
                            card.style.animation = 'none';
                            card.offsetHeight; // Trigger reflow
                            card.style.animation = `fadeInUp 0.6s ease forwards`;
                            card.style.animationDelay = `${index * 0.03}s`;
                        });
                    }, 300);
                }
            } else {
                // If not logged in, hide the loading overlay immediately
                loadingOverlay.style.display = 'none';
            }
        });
        
        // Function to fetch post details if not in current view
        function fetchPostDetails(postId) {
            fetch(`get_post_details.php?id=${postId}`)
                .then(response => response.json())
                .then(post => {
                    if (post.error) {
                        alert(post.error);
                        return;
                    }
                    
                    // Populate and show the modal
                    const modalTitle = document.querySelector('#postExpansionModal .modal-title');
                    const modalAuthor = document.querySelector('#postExpansionModal .post-author');
                    const modalDate = document.querySelector('#postExpansionModal .post-date');
                    const modalContent = document.querySelector('#postExpansionModal .post-content-expanded');
                    const modalActions = document.querySelector('#postExpansionModal .post-actions-expanded');
                    
                    modalTitle.textContent = post.title;
                    modalAuthor.textContent = post.is_anonymous ? 'Anonymous' : post.username;
                    modalDate.textContent = post.created_at;
                    modalContent.textContent = post.content;
                    document.querySelector('.comment-count').textContent = `${post.comment_count} comments`;
                    
                    // Update action buttons
                    const likeBtn = modalActions.querySelector('.action-btn-expanded:nth-child(1)');
                    const dislikeBtn = modalActions.querySelector('.action-btn-expanded:nth-child(2)');
                    
                    likeBtn.onclick = (e) => handleModalReaction(e, 'like');
                    dislikeBtn.onclick = (e) => handleModalReaction(e, 'dislike');
                    
                    likeBtn.querySelector('.like-count').textContent = post.like_count;
                    dislikeBtn.querySelector('.dislike-count').textContent = post.dislike_count;
                    
                    likeBtn.classList.toggle('active', post.user_liked);
                    dislikeBtn.classList.toggle('active', post.user_disliked);
                    
                    // Store post ID in modal for comment submission
                    document.getElementById('postExpansionModal').dataset.postId = postId;
                    
                    // Load comments for this post
                    loadComments(postId);
                    
                    // Show the modal
                    const postModal = new bootstrap.Modal(document.getElementById('postExpansionModal'));
                    postModal.show();
                })
                .catch(error => {
                    alert('Error loading post details');
                });
        }

        // Add event listener for trending time filter
        document.getElementById('trendingTimeFilter').addEventListener('change', function() {
            const timeFilter = this.value;
            // Update URL with new filter
            const url = new URL(window.location.href);
            url.searchParams.set('trending_time', timeFilter);
            window.history.pushState({}, '', url);
            
            // Show loading state
            const trendingContainer = document.getElementById('trendingPostsContainer');
            trendingContainer.innerHTML = '<div class="loading-trending">Loading trending posts...</div>';
            
            // Fetch updated trending posts
            fetch(`../get_trending_posts.php?time=${timeFilter}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.text();
                })
                .then(html => {
                    trendingContainer.innerHTML = html;
                    
                    // Reinitialize click events for new trending cards
                    const trendingCards = document.querySelectorAll('.trending-card');
                    trendingCards.forEach(card => {
                        card.addEventListener('click', function() {
                            const postId = this.dataset.postId;
                            // Find the corresponding post card and trigger its click event
                            const postCard = document.querySelector(`.post-card[data-post-id="${postId}"]`);
                            if (postCard) {
                                postCard.click();
                            } else {
                                // If the post is not in the current view, fetch it and show the modal
                                fetchPostDetails(postId);
                            }
                        });
                    });
                })
                .catch(error => {
                    console.error('Error fetching trending posts:', error);
                    trendingContainer.innerHTML = '<div class="error-trending">Error loading trending posts. Please try again.</div>';
                });
        });

        // Set initial trending time filter value based on URL parameter or default to 'present'
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const trendingTime = urlParams.get('trending_time') || 'present';
            document.getElementById('trendingTimeFilter').value = trendingTime;
        });

        // Function to handle reactions (likes/dislikes)
        function handleReaction(event, postId, action) {
            event.preventDefault();
            event.stopPropagation();
            
            // Get all instances of this post's action buttons (both in card and modal)
            const postCard = document.querySelector(`.post-card[data-post-id="${postId}"]`);
            const modal = document.getElementById('postExpansionModal');
            const isModalOpen = modal && modal.classList.contains('show') && modal.dataset.postId == postId;
            
            // Get all like/dislike buttons and counts for this post
            const allContainers = [postCard];
            if (isModalOpen) {
                allContainers.push(modal.querySelector('.post-actions-expanded'));
            }
            
            // Update UI immediately on all instances
            allContainers.forEach(container => {
                if (!container) return;
                
                const likeButton = container.querySelector('.action-btn:first-child');
                const dislikeButton = container.querySelector('.action-btn:nth-child(2)');
                const likeCount = likeButton.querySelector('.like-count');
                const dislikeCount = dislikeButton.querySelector('.dislike-count');
                
                const wasLiked = likeButton.classList.contains('active');
                const wasDisliked = dislikeButton.classList.contains('active');
                
                if (action === 'like') {
                    if (wasLiked) {
                        // Unlike
                        likeButton.classList.remove('active');
                        likeCount.textContent = parseInt(likeCount.textContent) - 1;
                    } else {
                        // Like
                        likeButton.classList.add('active');
                        likeCount.textContent = parseInt(likeCount.textContent) + 1;
                        
                        // Remove dislike if present
                        if (wasDisliked) {
                            dislikeButton.classList.remove('active');
                            dislikeCount.textContent = parseInt(dislikeCount.textContent) - 1;
                        }
                    }
                } else if (action === 'dislike') {
                    if (wasDisliked) {
                        // Remove dislike
                        dislikeButton.classList.remove('active');
                        dislikeCount.textContent = parseInt(dislikeCount.textContent) - 1;
                    } else {
                        // Dislike
                        dislikeButton.classList.add('active');
                        dislikeCount.textContent = parseInt(dislikeCount.textContent) + 1;
                        
                        // Remove like if present
                        if (wasLiked) {
                            likeButton.classList.remove('active');
                            likeCount.textContent = parseInt(likeCount.textContent) - 1;
                        }
                    }
                }
            });
            
            // Send request to server
            fetch('handle_reaction.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    post_id: postId,
                    action: action
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update all instances with server response
                    allContainers.forEach(container => {
                        if (!container) return;
                        
                        const likeButton = container.querySelector('.action-btn:first-child');
                        const dislikeButton = container.querySelector('.action-btn:nth-child(2)');
                        const likeCount = likeButton.querySelector('.like-count');
                        const dislikeCount = dislikeButton.querySelector('.dislike-count');
                        
                        // Update counts
                        likeCount.textContent = data.like_count;
                        dislikeCount.textContent = data.dislike_count;
                        
                        // Update button states
                        if (data.user_liked) {
                            likeButton.classList.add('active');
                        } else {
                            likeButton.classList.remove('active');
                        }
                        
                        if (data.user_disliked) {
                            dislikeButton.classList.add('active');
                        } else {
                            dislikeButton.classList.remove('active');
                        }
                    });
                } else {
                    console.error('Failed to update reaction:', data.error);
                }
            })
            .catch(error => {
                console.error('Error updating reaction:', error);
            });
        }

        // Helper function to update the UI for reactions
        function updateReactionUI(container, data) {
            const likeButton = container.querySelector('.action-btn:first-child');
            const dislikeButton = container.querySelector('.action-btn:nth-child(2)');
            const likeCount = likeButton.querySelector('.like-count');
            const dislikeCount = dislikeButton.querySelector('.dislike-count');
            
            // Update counts
            if (likeCount) likeCount.textContent = data.like_count;
            if (dislikeCount) dislikeCount.textContent = data.dislike_count;
            
            // Update button states
            if (data.user_liked) {
                likeButton.classList.add('active');
            } else {
                likeButton.classList.remove('active');
            }
            
            if (data.user_disliked) {
                dislikeButton.classList.add('active');
            } else {
                dislikeButton.classList.remove('active');
            }
        }
    </script>
</body>
</html> 