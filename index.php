<?php
session_start();

// Debug session data
error_log("Session data in index.php: " . print_r($_SESSION, true));

// If user is already logged in, redirect to their dashboard
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    error_log("User is logged in - Role: " . $_SESSION['role']);
  $redirect = null;
  switch ($_SESSION['role']) {
        case 'student':
      $redirect = 'student/dashboard.php';
            break;
        case 'instructor':
            error_log("Redirecting instructor to dashboard");
      $redirect = 'instructor/dashboard.php';
            break;
        case 'super_admin':
      $redirect = 'admin/dashboard.php';
            break;
        case 'admin':
      $redirect = 'registrar/dashboard.php';
            break;
    case 'registrar':
      $redirect = 'registrar/dashboard.php';
      break;
        default:
            error_log("Invalid role detected: " . $_SESSION['role']);
            // If invalid role somehow, destroy session and continue to role selection
            session_destroy();
      // Don't exit; continue rendering the landing page.
    }

  if ($redirect) {
    header('Location: ' . $redirect);
    exit();
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Welcome - PCT Bajada Classroom Scheduling System</title>
  
  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  
  <!-- CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
  <link rel="icon" href="pctlogo.png" type="image/png" />

  <style>
    :root {
      --primary: #164e2c;       /* Deep Green */
      --secondary: #2dbd5e;     /* Vibrant Green */
      --accent: #e8f3ec;        /* Soft Green */
      --muted: #6c757d;
      --light-bg: #fafdfb;      /* Almost White */
      --dark-text: #1a2b22;
    }

    html {
      scroll-padding-top: 80px;
    }

    body {
      font-family: 'Outfit', sans-serif;
      background-color: var(--light-bg);
      margin: 0;
      padding-top: 80px;
      color: var(--dark-text);
      scroll-behavior: smooth;
    }

    /* Navbar Glassmorphism */
    .navbar {
      background: rgba(22, 78, 44, 0.95);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      position: fixed;
      top: 0;
      width: 100%;
      z-index: 1030;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
      padding: 0.8rem 2rem;
      transition: all 0.3s ease;
    }

    .navbar-brand {
      display: flex;
      align-items: center;
      color: #fff !important;
      font-weight: 700;
      font-size: 1.6rem;
      gap: 1rem;
      text-decoration: none;
    }

    .navbar-brand img {
      height: 60px;
      width: auto;
      transition: transform 0.3s ease;
    }
    
    .navbar-brand:hover img {
      transform: scale(1.05);
    }

    .navbar-brand .tagline {
      font-size: 0.85rem;
      font-weight: 300;
      color: #d1e2d6;
      display: block;
      margin-top: 2px;
      letter-spacing: 0.5px;
    }

    .navbar-nav {
      gap: 1.5rem;
      align-items: center;
    }

    .nav-link {
      color: rgba(255, 255, 255, 0.9) !important;
      font-weight: 500;
      font-size: 1.05rem;
      position: relative;
      transition: all 0.3s ease;
      padding: 0.5rem 0 !important;
    }

    .nav-link::after {
      content: '';
      position: absolute;
      bottom: -2px;
      left: 0;
      height: 2px;
      width: 0;
      background-color: var(--secondary);
      transition: width 0.3s ease;
      border-radius: 2px;
    }

    .nav-link:hover::after,
    .nav-active::after {
      width: 100%;
    }

    .nav-link:hover {
      color: #fff !important;
    }

    .btn-login-nav {
      background-color: var(--secondary);
      color: #fff !important;
      font-weight: 600;
      padding: 0.5rem 1.5rem !important;
      border-radius: 50px;
      transition: all 0.3s ease;
    }

    .btn-login-nav:hover {
      background-color: #23a04e;
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(45, 189, 94, 0.3);
    }
    
    .navbar-toggler {
      border: none;
    }
    .navbar-toggler:focus {
      box-shadow: none;
    }
    .navbar-toggler-icon {
      background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba%28255,255,255,0.9%29' stroke-width='2.5' stroke-linecap='round' stroke-miterlimit='10' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
    }

    /* Hero Section */
    .hero {
      position: relative;
      background: url('https://images.unsplash.com/photo-1541339907198-e08756dedf3f?ixlib=rb-4.0.3&auto=format&fit=crop&w=1470&q=80') center center/cover no-repeat;
      min-height: calc(100vh - 80px);
      display: flex;
      justify-content: center;
      align-items: center;
      text-align: center;
      color: white;
      overflow: hidden;
    }

    .hero::before {
      content: "";
      position: absolute;
      inset: 0;
      background: linear-gradient(135deg, rgba(22, 78, 44, 0.85) 0%, rgba(13, 33, 22, 0.75) 100%);
      z-index: 0;
    }

    .hero-content {
      position: relative;
      z-index: 1;
      padding: 2rem;
      max-width: 800px;
    }

    .hero h1 {
      font-size: 4rem;
      font-weight: 800;
      margin-bottom: 1.5rem;
      line-height: 1.1;
      text-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
    }

    .hero p {
      font-size: 1.3rem;
      font-weight: 400;
      margin-bottom: 2.5rem;
      color: #e8f3ec;
    }

    .hero-buttons .btn {
      padding: 0.8rem 2.5rem;
      font-size: 1.1rem;
      border-radius: 50px;
      font-weight: 600;
      margin: 0.5rem;
      transition: all 0.3s ease;
    }

    .btn-hero-primary {
      background-color: var(--secondary);
      color: white;
      border: none;
      box-shadow: 0 4px 15px rgba(45, 189, 94, 0.3);
    }

    .btn-hero-primary:hover {
      background-color: #23a04e;
      color: white;
      transform: translateY(-3px);
      box-shadow: 0 6px 20px rgba(45, 189, 94, 0.4);
    }

    .btn-hero-outline {
      background-color: transparent;
      color: white;
      border: 2px solid rgba(255,255,255,0.7);
    }

    .btn-hero-outline:hover {
      background-color: rgba(255,255,255,0.1);
      color: white;
      border-color: white;
      transform: translateY(-3px);
    }

    /* Section Headings */
    .section-heading {
      text-align: center;
      font-weight: 800;
      margin-bottom: 1rem;
      color: var(--primary);
      font-size: 2.5rem;
      position: relative;
      display: inline-block;
    }
    
    .section-subheading {
      text-align: center;
      color: var(--muted);
      font-size: 1.1rem;
      margin-bottom: 3.5rem;
      max-width: 600px;
      margin-left: auto;
      margin-right: auto;
    }

    /* Features Section */
    #features {
      padding: 6rem 0;
      background-color: var(--light-bg);
    }

    .feature-card {
      background: #fff;
      border-radius: 16px;
      padding: 2.5rem 2rem;
      text-align: center;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.04);
      height: 100%;
      transition: all 0.4s cubic-bezier(0.165, 0.84, 0.44, 1);
      border: 1px solid rgba(0,0,0,0.03);
    }

    .feature-card:hover {
      transform: translateY(-10px);
      box-shadow: 0 20px 40px rgba(22, 78, 44, 0.1);
      border-color: var(--accent);
    }

    .feature-icon-wrapper {
      width: 80px;
      height: 80px;
      background-color: var(--accent);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 1.5rem;
      color: var(--primary);
      font-size: 2rem;
      transition: all 0.3s ease;
    }

    .feature-card:hover .feature-icon-wrapper {
      background-color: var(--primary);
      color: white;
      transform: scale(1.1);
    }

    .feature-card h3 {
      font-size: 1.4rem;
      font-weight: 700;
      margin-bottom: 1rem;
      color: var(--dark-text);
    }

    .feature-card p {
      color: #55694a;
      line-height: 1.6;
      margin: 0;
    }

    /* About Section */
    #about {
      background: var(--accent);
      padding: 6rem 0;
    }

    .about-image {
      position: relative;
    }
    
    .about-image img {
      border-radius: 16px;
      box-shadow: 0 20px 40px rgba(0,0,0,0.1);
      width: 100%;
      height: auto;
      object-fit: cover;
    }

    .about-image::after {
      content: '';
      position: absolute;
      top: -15px;
      left: -15px;
      bottom: 15px;
      right: 15px;
      border: 3px solid var(--primary);
      border-radius: 16px;
      z-index: -1;
    }

    .about-content h2 {
      font-weight: 800;
      color: var(--primary);
      font-size: 2.5rem;
      margin-bottom: 1.5rem;
    }

    .about-content p {
      font-size: 1.1rem;
      line-height: 1.8;
      color: #4a5c53;
      margin-bottom: 1.5rem;
    }

    /* Contact Section */
    #contact {
      padding: 6rem 0;
      background-color: #fff;
    }

    .contact-card {
      background: #fff;
      border-radius: 16px;
      box-shadow: 0 10px 40px rgba(0,0,0,0.06);
      padding: 3rem;
      height: 100%;
    }

    .contact-info-item {
      display: flex;
      align-items: flex-start;
      margin-bottom: 1.5rem;
    }

    .contact-info-icon {
      color: var(--secondary);
      font-size: 1.5rem;
      margin-right: 1rem;
      margin-top: 2px;
    }

    .contact-info-content h4 {
      font-size: 1.1rem;
      font-weight: 700;
      margin-bottom: 0.2rem;
      color: var(--dark-text);
    }
    
    .contact-info-content p, .contact-info-content a {
      color: var(--muted);
      margin: 0;
      text-decoration: none;
    }
    
    .contact-info-content a:hover {
      color: var(--primary);
    }

    .form-control {
      padding: 0.8rem 1rem;
      border-radius: 8px;
      border: 1px solid #dee2e6;
      background-color: #f8fcf9;
    }
    
    .form-control:focus {
      border-color: var(--secondary);
      box-shadow: 0 0 0 0.25rem rgba(45, 189, 94, 0.25);
      background-color: #fff;
    }

    .form-label {
      font-weight: 500;
      color: var(--dark-text);
    }

    .btn-submit {
      background-color: var(--primary);
      border: none;
      font-weight: 600;
      padding: 0.8rem;
      border-radius: 8px;
      color: white;
      width: 100%;
      transition: all 0.3s ease;
    }

    .btn-submit:hover {
      background-color: #0c2c19;
      transform: translateY(-2px);
      box-shadow: 0 8px 15px rgba(22, 78, 44, 0.2);
    }

    .map-container {
      border-radius: 12px;
      overflow: hidden;
      box-shadow: 0 5px 20px rgba(0,0,0,0.05);
      height: 100%;
      min-height: 400px;
    }

    /* Footer */
    footer {
      background-color: #121c17;
      color: rgba(255,255,255,0.7);
      padding: 3rem 0 1.5rem;
    }
    
    .footer-content {
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      margin-bottom: 2rem;
    }

    .footer-logo {
      height: 50px;
      margin-bottom: 1rem;
      opacity: 0.9;
    }

    .footer-divider {
      border-color: rgba(255,255,255,0.1);
      margin: 0 0 1.5rem 0;
    }

    .footer-bottom {
      text-align: center;
      font-size: 0.9rem;
    }

    /* Back to Top button */
    #backToTopBtn {
      position: fixed;
      bottom: 30px;
      right: 30px;
      background-color: var(--secondary);
      color: white;
      border: none;
      width: 45px;
      height: 45px;
      border-radius: 50%;
      font-size: 1.2rem;
      cursor: pointer;
      box-shadow: 0 4px 12px rgba(45, 189, 94, 0.4);
      transition: all 0.3s ease;
      z-index: 1100;
      opacity: 0;
      visibility: hidden;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    #backToTopBtn.show {
      opacity: 1;
      visibility: visible;
    }

    #backToTopBtn:hover {
      background-color: #23a04e;
      transform: translateY(-3px);
    }

    @media (max-width: 991px) {
      .hero h1 {
        font-size: 3rem;
      }
      .navbar-brand img {
        height: 50px;
      }
      .navbar-brand {
        font-size: 1.3rem;
      }
      .navbar-nav {
        padding: 1.5rem 0;
      }
      .btn-login-nav {
        display: inline-block;
        margin-top: 1rem;
      }
    }
    
    @media (max-width: 768px) {
      .hero h1 {
        font-size: 2.2rem;
      }
      .hero p {
        font-size: 1.1rem;
      }
      .about-image {
        margin-bottom: 2rem;
      }
      .about-image::after {
        display: none;
      }
    }
  </style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar navbar-expand-lg">
  <div class="container">
    <a href="#home" class="navbar-brand">
      <img src="pctlogo.png" alt="PCT Logo" />
      <div>
        Philippine College of Technology
        <span class="tagline">Bajada Campus Classroom Scheduling</span>
      </div>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarCollapse">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarCollapse">
      <div class="navbar-nav ms-auto">
        <a href="#home" class="nav-link">Home</a>
        <a href="#features" class="nav-link">Features</a>
        <a href="#about" class="nav-link">About</a>
        <a href="#contact" class="nav-link">Contact</a>
        <a href="auth/login.php" class="nav-link btn-login-nav ms-lg-3">Login</a>
      </div>
    </div>
  </div>
</nav>

<!-- HERO SECTION -->
<header id="home" class="hero">
  <div class="hero-content" data-aos="fade-up" data-aos-duration="1000">
    <h1>Classroom Scheduling System</h1>
    <p>A simple way to manage and view class schedules, rooms, and instructor assignments for PCT Bajada.</p>
    <div class="hero-buttons">
      <a href="auth/login.php" class="btn btn-hero-primary">Get Started</a>
      <a href="#about" class="btn btn-hero-outline">Learn More</a>
    </div>
  </div>
</header>

<!-- FEATURES SECTION -->
<section id="features">
  <div class="container">
    <div class="text-center" data-aos="fade-up">
      <h2 class="section-heading">Why Use Our System?</h2>
      <p class="section-subheading">A comprehensive platform designed to streamline academic scheduling for everyone involved.</p>
    </div>
    
    <div class="row g-4 mt-2">
      <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
        <div class="feature-card">
          <div class="feature-icon-wrapper">
            <i class="bi bi-calendar-check mt-2"></i>
          </div>
          <h3>Conflict Checking</h3>
          <p>Automatically checks for overlapping schedules to prevent room and instructor conflicts during block assignments.</p>
        </div>
      </div>
      
      <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
        <div class="feature-card">
          <div class="feature-icon-wrapper">
            <i class="bi bi-phone mt-2"></i>
          </div>
          <h3>Online Access</h3>
          <p>Instructors and students can view their updated class schedules directly from their computer or mobile device.</p>
        </div>
      </div>
      
      <div class="col-md-4" data-aos="fade-up" data-aos-delay="300">
        <div class="feature-card">
          <div class="feature-icon-wrapper">
            <i class="bi bi-kanban mt-2"></i>
          </div>
          <h3>Centralized Dashboard</h3>
          <p>Organize and monitor all classroom assignments in one central platform instead of relying on manual records.</p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ABOUT SECTION -->
<section id="about" aria-labelledby="aboutHeading">
  <div class="container">
    <div class="row align-items-center g-5">
      <div class="col-lg-6" data-aos="fade-right">
        <div class="about-image">
          <img src="https://images.unsplash.com/photo-1577896851231-70ef18881754?ixlib=rb-4.0.3&auto=format&fit=crop&w=1000&q=80" alt="Students learning" />
        </div>
      </div>
      <div class="col-lg-6" data-aos="fade-left">
        <div class="about-content">
          <h2 id="aboutHeading">About the Project</h2>
          <p>
            The Classroom Scheduling System was developed for the Philippine College of Technology - Bajada Campus to help manage class schedules more efficiently. It provides a platform where the registrar, instructors, and students can view and organize class information easily.
          </p>
          <p>
            Administrators can set up courses and assign rooms, while instructors can check their assigned classes online. The main goal of this project is to reduce manual paperwork, prevent scheduling errors, and make viewing schedules easier for everyone.
          </p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- CONTACT SECTION -->
<section id="contact" aria-labelledby="contactHeading">
  <div class="container">
    <div class="text-center" data-aos="fade-up">
      <h2 id="contactHeading" class="section-heading">Contact Information</h2>
      <p class="section-subheading">If you have questions about the schedules, please contact the administration office.</p>
    </div>
    
    <div class="row g-4 mt-2">
      <!-- Contact Info & Form -->
      <div class="col-lg-5" data-aos="fade-up" data-aos-delay="100">
        <div class="contact-card">
          <!-- Contact details here -->
          <div class="contact-info-item">
            <div class="contact-info-icon"><i class="bi bi-geo-alt-fill"></i></div>
            <div class="contact-info-content">
              <h4>Location</h4>
              <p>Garden Park Village, Bajada, Davao City</p>
            </div>
          </div>
          
          <div class="contact-info-item">
            <div class="contact-info-icon"><i class="bi bi-telephone-fill"></i></div>
            <div class="contact-info-content">
              <h4>Phone</h4>
              <p>(082) 221-0381</p>
            </div>
          </div>
          
          <div class="contact-info-item mb-4">
            <div class="contact-info-icon"><i class="bi bi-envelope-fill"></i></div>
            <div class="contact-info-content">
              <h4>Email</h4>
              <a href="mailto:pctdvo@yahoo.com">pctdvo@yahoo.com</a>
            </div>
          </div>
          
          <hr class="mb-4">
          
          <form onsubmit="event.preventDefault(); alert('Thank you for reaching out! We will get back to you shortly.'); this.reset();">
            <div class="mb-3">
              <label for="contactName" class="form-label">Full Name</label>
              <input type="text" class="form-control" id="contactName" placeholder="e.g. John Doe" required />
            </div>
            <div class="mb-3">
              <label for="contactEmail" class="form-label">Email Address</label>
              <input type="email" class="form-control" id="contactEmail" placeholder="name@example.com" required />
            </div>
            <div class="mb-4">
              <label for="contactMessage" class="form-label">Message</label>
              <textarea class="form-control" id="contactMessage" rows="3" placeholder="How can we help you?" required></textarea>
            </div>
            <button type="submit" class="btn-submit">Send Message <i class="bi bi-send ms-2"></i></button>
          </form>
        </div>
      </div>
      
      <!-- Map -->
      <div class="col-lg-7" data-aos="fade-up" data-aos-delay="200">
        <div class="map-container">
          <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3959.0886869408037!2d125.60874!3d7.08571!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x32f96d07e1e69b0f%3A0xc0f1f1d1e1f1d1e1!2sPhilippine%20College%20of%20Technology%20-%20Bajada%20Campus%2C%20Garden%20Park%20Village%2C%20Bajada%2C%20Davao%20City!5e0!3m2!1sen!2sph!4v1678888888888!5m2!1sen!2sph" width="100%" height="100%" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- FOOTER -->
<footer>
  <div class="container">
    <div class="footer-content">
      <img src="pctlogo.png" alt="PCT Logo" class="footer-logo">
      <h5 class="text-white mb-2">Philippine College of Technology</h5>
      <p class="mb-0">Classroom Scheduling System - Bajada Campus</p>
    </div>
    <hr class="footer-divider">
    <div class="footer-bottom">
      <p>&copy; <?php echo date("Y"); ?> PCT Bajada. All rights reserved.</p>
    </div>
  </div>
</footer>

<!-- BACK TO TOP BUTTON -->
<button id="backToTopBtn" aria-label="Back to top">
  <i class="bi bi-arrow-up"></i>
</button>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script>
  // Initialize Animate On Scroll (AOS)
  AOS.init({
    once: true,
    offset: 100,
    duration: 800,
  });

  // Back to Top button functionality
  const backToTopBtn = document.getElementById('backToTopBtn');
  window.addEventListener('scroll', () => {
    if (window.scrollY > 300) {
      backToTopBtn.classList.add('show');
    } else {
      backToTopBtn.classList.remove('show');
    }
  });
  
  backToTopBtn.addEventListener('click', () => {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });

  // Active link state
  const sections = document.querySelectorAll('section, header');
  const navLinks = document.querySelectorAll('.navbar-nav .nav-link:not(.btn-login-nav)');
  
  window.addEventListener('scroll', () => {
    let current = '';
    sections.forEach(section => {
      const sectionTop = section.offsetTop;
      const sectionHeight = section.clientHeight;
      if (scrollY >= (sectionTop - 150)) {
        current = section.getAttribute('id');
      }
    });

    navLinks.forEach(link => {
      link.classList.remove('nav-active');
      if (link.getAttribute('href').includes(current)) {
        link.classList.add('nav-active');
      }
    });
  });
</script>
</body>
</html> 