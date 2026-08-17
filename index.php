<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
/**
 * index.php — Public Landing Page
 * Content is dynamically pulled from site_settings if available.
 */
require_once __DIR__ . '/config/db.php';
$siteSettings = [];
$heroVideo = null;
try {
    $pdo = db();
    $rows = $pdo->query("SELECT setting_key, setting_value FROM site_settings")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) $siteSettings[$r['setting_key']] = $r['setting_value'];
    
    // Fetch active background video path
    $stmtVid = $pdo->query("SELECT video_path FROM hero_videos WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
    if ($stmtVid) {
        $heroVideo = $stmtVid->fetchColumn();
    }

    // Fetch active reports showcase video path
    $showcaseVideo = null;
    $stmtShowcase = $pdo->query("SELECT video_path FROM hero_showcase_videos WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
    if ($stmtShowcase) {
        $showcaseVideo = $stmtShowcase->fetchColumn();
    }
} catch (Exception $e) { /* Table or records may not exist yet */ }

function ss(string $key, string $default = ''): string {
    global $siteSettings;
    return htmlspecialchars($siteSettings[$key] ?? $default, ENT_QUOTES, 'UTF-8');
}
function ssRaw(string $key, string $default = ''): string {
    global $siteSettings;
    return $siteSettings[$key] ?? $default;
}
function heroTitle(string $key, string $default): string {
    global $siteSettings;
    $raw = $siteSettings[$key] ?? $default;
    // Convert [em]...[/em] to <em>...</em>
    $raw = preg_replace('/\[em\](.*?)\[\/em\]/s', '<em>$1</em>', htmlspecialchars($raw, ENT_QUOTES, 'UTF-8'));
    return $raw;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="Foursquare Gospel Church digital reporting system — monthly financial and spiritual reports for local churches and zonal offices.">
<title><?= ss('site_name','Foursquare Reports') ?> Church &amp; Zonal Reporting</title>
<link rel="icon" type="image/jpeg" href="assets/logo.jpg">
<link rel="stylesheet" href="assets/style.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --navy:       #1A1040;
    --red:        #E31E24;
    --red-hover:  #C41920;
    --ink:        #0D0D12;
    --ink-soft:   #52525B;
    --ink-faint:  #A1A1AA;
    --paper:      #FAF9F6;
    --card:       #FFFFFF;
    --line:       #E4E4E7;
    --line-dark:  #D4D4D8;
    --gold:       #F5A41D;
    --font-body:  'Inter', system-ui, sans-serif;
    --font-disp:  'Outfit', 'Inter', sans-serif;
    --radius:     10px;
    --radius-sm:  7px;
    --transition: all 0.18s ease;
  }

  html { scroll-behavior: smooth; }
  body { font-family: var(--font-body); background: var(--paper); color: var(--ink); line-height: 1.6; }
  a { text-decoration: none; color: inherit; }

  /* ── Eyebrow ── */
  .eyebrow {
    display: inline-block; font-size: 11px; font-weight: 700;
    letter-spacing: 0.10em; text-transform: uppercase;
    color: rgba(255,255,255,0.55); margin-bottom: 14px;
  }
  .eyebrow-dark { color: var(--ink-faint); }

  /* ── Buttons ── */
  .btn {
    display: inline-flex; align-items: center; justify-content: center;
    padding: 11px 24px; border-radius: var(--radius-sm);
    font-size: 14px; font-weight: 600; font-family: var(--font-body);
    cursor: pointer; border: 2px solid transparent;
    transition: var(--transition); white-space: nowrap; gap: 7px;
  }
  .btn-solid {
    background: var(--red); color: #fff; border-color: var(--red);
  }
  .btn-solid:hover { background: var(--red-hover); border-color: var(--red-hover); transform: translateY(-1px); box-shadow: 0 6px 20px rgba(227,30,36,0.35); }
  .btn-outline { background: transparent; color: #fff; border-color: rgba(255,255,255,0.45); }
  .btn-outline:hover { border-color: #fff; background: rgba(255,255,255,0.10); }
  .btn-outline-dark { background: transparent; color: var(--navy); border-color: var(--line-dark); }
  .btn-outline-dark:hover { border-color: var(--navy); background: rgba(26,16,64,0.05); }

  /* ── HERO ── */
  /* Desktop: pull hero under the fixed header so bg covers full viewport height */
  /* Mobile: do NOT pull up — let padding-top on body push hero below the header */
  .hero {
    background: var(--navy);
    position: relative;
    overflow: hidden;
  }
  /* Desktop only — allow hero to overlap behind fixed header */
  @media (min-width: 561px) {
    .hero { margin-top: calc(-1 * var(--header-h)); padding-top: var(--header-h); }
  }
  /* Mobile — ensure hero content is never hidden by the fixed header */
  @media (max-width: 560px) {
    .hero { margin-top: 0; padding-top: 0; }
    /* body.has-header already adds padding-top: var(--header-h) globally */
  }
  .hero-bg-video {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    z-index: 0;
    opacity: 0.25;
    pointer-events: none;
  }
  .hero::before {
    content: ''; position: absolute; inset: 0;
    background-image:
      repeating-linear-gradient(0deg, transparent, transparent 39px, rgba(255,255,255,0.025) 39px, rgba(255,255,255,0.025) 40px),
      repeating-linear-gradient(90deg, transparent, transparent 39px, rgba(255,255,255,0.025) 39px, rgba(255,255,255,0.025) 40px);
    pointer-events: none;
  }
  .hero::after {
    content: ''; position: absolute; bottom: -60px; right: -60px;
    width: 320px; height: 320px; border-radius: 50%;
    background: radial-gradient(circle, rgba(227,30,36,0.20) 0%, transparent 70%);
    pointer-events: none;
  }
  .hero-inner {
    max-width: 1160px; margin: 0 auto;
    display: grid; grid-template-columns: 1fr 340px;
    gap: 56px; align-items: center;
    padding: 90px 32px 96px; position: relative; z-index: 1;
  }
  .hero-text h1 {
    font-family: var(--font-disp);
    font-size: clamp(34px, 5vw, 58px);
    font-weight: 800; line-height: 1.08;
    letter-spacing: -0.03em; color: #fff; margin-bottom: 22px;
  }
  .hero-text h1 em {
    font-style: normal;
    background: linear-gradient(135deg, #FF6B35 0%, var(--red) 100%);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
  }
  .hero-text .lead { font-size: 16px; color: rgba(255,255,255,0.72); line-height: 1.80; max-width: 500px; margin-bottom: 36px; }
  .hero-ctas { display: flex; gap: 12px; flex-wrap: wrap; }
  /* ── HERO SHOWCASE ── */
  .hero-showcase-panel {
    position: relative;
    width: 100%;
    max-width: 360px;
    height: 480px;
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 20px;
    background: rgba(255,255,255,0.05);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    overflow: hidden;
    box-shadow: 0 24px 60px rgba(0,0,0,0.4), inset 0 1px 0 rgba(255,255,255,0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    align-self: center;
    transition: var(--transition);
  }
  .hero-showcase-panel:hover {
    border-color: rgba(255,255,255,0.25);
    box-shadow: 0 32px 80px rgba(0,0,0,0.5), inset 0 1px 0 rgba(255,255,255,0.2);
  }
  .hero-showcase-video {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 18px;
  }
  .hero-logo-panel {
    display: flex; align-items: center; justify-content: center;
    border: 1px solid rgba(255,255,255,0.10); border-radius: 20px;
    background: rgba(255,255,255,0.06); backdrop-filter: blur(10px);
    aspect-ratio: 1; overflow: hidden; padding: 36px;
    width: 100%; max-width: 340px; align-self: center;
  }
  .hero-logo-panel img { width: 100%; max-width: 200px; object-fit: contain; border-radius: 12px; }

  /* ── FEATURES STRIP ── */
  .features-strip { background: var(--card); border-bottom: 1px solid var(--line); }
  .features-strip-inner {
    max-width: 1160px; margin: 0 auto;
    display: grid; grid-template-columns: repeat(4, 1fr); padding: 0 32px;
  }
  .feature-cell { padding: 28px 20px; border-right: 1px solid var(--line); display: flex; align-items: flex-start; gap: 14px; transition: var(--transition); }
  .feature-cell:last-child { border-right: none; }
  .feature-cell:hover { background: rgba(26,16,64,0.02); }
  .feature-icon { width: 38px; height: 38px; flex-shrink: 0; background: var(--navy); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #fff; }
  .feature-cell-text strong { display: block; font-size: 13px; font-weight: 700; color: var(--navy); margin-bottom: 4px; }
  .feature-cell-text span { font-size: 12px; color: var(--ink-faint); line-height: 1.5; }

  /* ── PATHS ── */
  .paths { background: var(--paper); border-bottom: 1px solid var(--line); padding: 90px 32px; }
  .section-head { max-width: 600px; margin: 0 auto 56px; text-align: center; }
  .section-head h2 { font-family: var(--font-disp); font-size: clamp(26px, 3vw, 38px); font-weight: 800; color: var(--navy); letter-spacing: -0.025em; margin-bottom: 14px; }
  .section-head p { font-size: 15px; color: var(--ink-soft); line-height: 1.75; }
  .path-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; max-width: 960px; margin: 0 auto; }
  .path-card { border-radius: 16px; padding: 44px 40px; display: flex; flex-direction: column; border: 1px solid var(--line); transition: var(--transition); }
  .path-card:hover { transform: translateY(-3px); box-shadow: 0 12px 32px rgba(0,0,0,0.09); }
  .path-card.dark { background: var(--navy); color: rgba(255,255,255,0.80); border-color: transparent; box-shadow: 0 8px 28px rgba(26,16,64,0.25); }
  .path-card.light { background: var(--card); }
  .path-icon { width: 48px; height: 48px; border-radius: 10px; border: 1px solid rgba(255,255,255,0.15); display: flex; align-items: center; justify-content: center; margin-bottom: 22px; background: rgba(255,255,255,0.08); color: rgba(255,255,255,0.9); }
  .path-card.light .path-icon { border-color: var(--line); background: var(--paper); color: var(--navy); }
  .path-card h3 { font-family: var(--font-disp); font-size: 20px; font-weight: 800; color: #fff; margin-bottom: 10px; }
  .path-card.light h3 { color: var(--navy); }
  .path-card > p { font-size: 14px; color: rgba(255,255,255,0.65); line-height: 1.7; margin-bottom: 24px; }
  .path-card.light > p { color: var(--ink-soft); }
  .path-card ul { list-style: none; display: flex; flex-direction: column; gap: 10px; margin-bottom: 36px; flex: 1; }
  .path-card li { display: flex; align-items: flex-start; gap: 10px; font-size: 13.5px; color: rgba(255,255,255,0.70); }
  .path-card.light li { color: var(--ink-soft); }
  .path-card li .chk { width: 18px; height: 18px; border-radius: 50%; background: rgba(227,30,36,0.20); border: 1.5px solid rgba(227,30,36,0.40); display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 9px; color: var(--red); margin-top: 1px; font-weight: 800; }
  .path-card.light li .chk { background: rgba(26,16,64,0.06); border-color: rgba(26,16,64,0.15); color: var(--navy); }
  .path-divider { height: 1px; background: rgba(255,255,255,0.10); margin-bottom: 24px; }
  .path-card.light .path-divider { background: var(--line); }

  /* ── HOW IT WORKS ── */
  .how { background: var(--navy); padding: 90px 32px; }
  .how-inner { max-width: 1160px; margin: 0 auto; }
  .how .section-head { margin-bottom: 56px; text-align: left; max-width: 500px; margin-left: 0; }
  .how .section-head .eyebrow { color: rgba(255,255,255,0.40); }
  .how .section-head h2 { color: #fff; }
  .how-steps { display: grid; grid-template-columns: repeat(4,1fr); gap: 16px; }
  .how-step { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.08); border-radius: 14px; padding: 32px 24px; transition: var(--transition); }
  .how-step:hover { background: rgba(255,255,255,0.08); transform: translateY(-2px); }
  .step-num { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 50%; background: var(--red); color: #fff; font-family: var(--font-disp); font-size: 13px; font-weight: 800; margin-bottom: 20px; }
  .how-step h4 { font-family: var(--font-disp); font-size: 17px; font-weight: 700; color: #fff; margin-bottom: 10px; }
  .how-step p  { font-size: 13.5px; color: rgba(255,255,255,0.55); line-height: 1.70; }

  /* ── FOOTER ── */
  footer { background: var(--navy); padding: 40px 32px; }
  .footer-inner { max-width: 1160px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; }
  .footer-brand { display: flex; align-items: center; gap: 10px; }
  .footer-brand img { height: 28px; width: 28px; object-fit: cover; border-radius: 6px; border: 1px solid rgba(255,255,255,0.15); }
  .footer-brand span { font-size: 14px; font-weight: 700; color: #fff; }
  .footer-links { display: flex; gap: 4px; align-items: center; }
  .footer-links a { font-size: 13px; color: rgba(255,255,255,0.50); padding: 5px 10px; border-radius: var(--radius-sm); transition: var(--transition); }
  .footer-links a:hover { color: #fff; background: rgba(255,255,255,0.08); }
  .footer-copy { font-size: 12px; color: rgba(255,255,255,0.35); }

  /* ── RESPONSIVE ── */
  @media (max-width: 900px) {
    .hero-inner { grid-template-columns: 1fr; padding: 64px 24px 72px; justify-items: center; text-align: center; }
    .hero-text { display: flex; flex-direction: column; align-items: center; }
    .hero-showcase-panel { margin-top: 32px; width: 100%; max-width: 320px; height: 420px; }
    .hero-logo-panel { margin-top: 32px; width: 100%; max-width: 300px; padding: 24px; }
    .features-strip-inner { grid-template-columns: 1fr 1fr; }
    .feature-cell:nth-child(2) { border-right: none; }
    .feature-cell:nth-child(3), .feature-cell:nth-child(4) { border-top: 1px solid var(--line); }
    .feature-cell:nth-child(4) { border-right: none; }
    .path-grid { grid-template-columns: 1fr; gap: 16px; }
    .how-steps { grid-template-columns: 1fr 1fr; gap: 12px; }
  }
  @media (max-width: 560px) {
    .nav-row { padding: 0 16px; }
    /* Hero padding on mobile: just enough so content clears the fixed header */
    .hero-inner { padding: 24px 16px 52px; }
    .hero-text h1 { font-size: 28px; line-height: 1.12; }
    .hero-text .lead { font-size: 14px; margin-bottom: 28px; }
    .hero-ctas { flex-direction: column; }
    .hero-ctas .btn { width: 100%; justify-content: center; }
    .hero-showcase-panel { margin-top: 24px; width: 100%; max-width: 270px; height: 350px; }
    .hero-logo-panel { margin-top: 24px; width: 100%; max-width: 250px; padding: 16px; }
    .features-strip-inner { grid-template-columns: 1fr; padding: 0 16px; }
    .feature-cell { border-right: none; border-bottom: 1px solid var(--line); }
    .feature-cell:last-child { border-bottom: none; }
    .paths { padding: 56px 16px; }
    .path-card { padding: 32px 24px; }
    .how { padding: 56px 16px; }
    .how-steps { grid-template-columns: 1fr; }
    footer { padding: 32px 16px; }
    .footer-inner { flex-direction: column; align-items: flex-start; gap: 20px; }
  }
</style>
</head>
<body class="has-header">

<!-- ═══ HEADER ═══════════════════════════════════════════ -->
<?php include __DIR__ . '/includes/header.php'; ?>



<!-- ═══ HERO ═════════════════════════════════════════════ -->
<section class="hero">
  <?php 
    $activeVideoSource = !empty($heroVideo) ? $heroVideo : ($siteSettings['hero_video_url'] ?? '');
    if (!empty($activeVideoSource)): 
  ?>
    <video autoplay muted loop playsinline preload="metadata" class="hero-bg-video">
      <source src="<?= htmlspecialchars($activeVideoSource, ENT_QUOTES, 'UTF-8') ?>" type="video/mp4">
    </video>
  <?php endif; ?>
  <div class="hero-inner">
    <div class="hero-text">
      <span class="eyebrow">For churches and zones nationwide</span>
      <h1><?= heroTitle('hero_title', 'Monthly reports, [em]finally in order.[/em]') ?></h1>
      <p class="lead"><?= ss('hero_lead', 'Replace the paper financial and spiritual report sheets with one system that calculates dues, tracks attendance, and keeps every month on file — for local churches and zonal offices alike.') ?></p>
      <div class="hero-ctas">
        <a href="#paths" class="btn btn-solid">Get started</a>
        <a href="login.php" class="btn btn-outline">I already have an account</a>
      </div>
    </div>
    <?php 
      $activeShowcaseSource = null;
      if (!empty($showcaseVideo)) {
          $activeShowcaseSource = $showcaseVideo;
      } elseif (!empty($siteSettings['showcase_video_url'])) {
          $activeShowcaseSource = $siteSettings['showcase_video_url'];
      }
    ?>
    <?php if ($activeShowcaseSource): ?>
      <div class="hero-showcase-panel">
        <video autoplay muted loop playsinline preload="metadata" class="hero-showcase-video">
          <source src="<?= htmlspecialchars($activeShowcaseSource, ENT_QUOTES, 'UTF-8') ?>" type="video/mp4">
        </video>
      </div>
    <?php else: ?>
      <div class="hero-logo-panel">
        <img src="assets/logo.jpg" alt="Foursquare Gospel Church — Savior, Healer, Baptizer, Coming King">
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- ═══ FEATURES STRIP ════════════════════════════════════ -->
<div class="features-strip">
  <div class="features-strip-inner">
    <div class="feature-cell">
      <div class="feature-icon">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 22V10L12 3l9 7v12"/><path d="M9 22V15h6v7"/><path d="M12 3v4"/></svg>
      </div>
      <div class="feature-cell-text">
        <strong>Chartered &amp; unchartered</strong>
        <span>Works for any local church, any type</span>
      </div>
    </div>
    <div class="feature-cell">
      <div class="feature-icon">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="12" width="4" height="9" rx="1"/><rect x="10" y="7" width="4" height="14" rx="1"/><rect x="17" y="3" width="4" height="18" rx="1"/></svg>
      </div>
      <div class="feature-cell-text">
        <strong>Dues auto-calculated</strong>
        <span>Set centrally churches just enter income</span>
      </div>
    </div>
    <div class="feature-cell">
      <div class="feature-icon">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      </div>
      <div class="feature-cell-text">
        <strong>A4 PDF output</strong>
        <span>Printable report, ready for signatures</span>
      </div>
    </div>
    <div class="feature-cell">
      <div class="feature-icon">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.66 4.03 3 9 3s9-1.34 9-3V5"/><path d="M3 12c0 1.66 4.03 3 9 3s9-1.34 9-3"/></svg>
      </div>
      <div class="feature-cell-text">
        <strong>Full history on file</strong>
        <span>Every submitted month saved permanently</span>
      </div>
    </div>
  </div>
</div>

<!-- ═══ PATHS ════════════════════════════════════════════ -->
<section class="paths" id="paths">
  <div class="section-head">
    <span class="eyebrow eyebrow-dark">Choose your report type</span>
    <h2><?= ss('paths_title', 'Two kinds of reporting, one system.') ?></h2>
    <p><?= ss('paths_subtitle', 'Your church submits its own monthly report. Your zone compares reports across every church under it.') ?></p>
  </div>

  <div class="path-grid">
    <!-- Church card (dark) -->
    <div class="path-card dark">
      <div class="path-icon">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 22V10L12 3l9 7v12"/><path d="M9 22V15h6v7"/><path d="M12 3v4"/></svg>
      </div>
      <h3>Register a local church</h3>
      <p>For chartered and unchartered churches submitting the monthly financial and spiritual report.</p>
      <ul>
        <li><div class="chk">✓</div> Dues calculated for your church type automatically</li>
        <li><div class="chk">✓</div> Tithes, offerings &amp; attendance totals done for you</li>
        <li><div class="chk">✓</div> Every month saved and exportable</li>
      </ul>
      <div class="path-divider"></div>
      <a href="register_church.php" class="btn btn-solid" style="width:100%;justify-content:center;">Register your church</a>
    </div>

    <!-- Zone card (light) -->
    <div class="path-card light">
      <div class="path-icon">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="22" x2="21" y2="22"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="12" y1="3" x2="12" y2="9"/><polyline points="3 9 12 3 21 9"/><line x1="6" y1="9" x2="6" y2="22"/><line x1="10" y1="9" x2="10" y2="22"/><line x1="14" y1="9" x2="14" y2="22"/><line x1="18" y1="9" x2="18" y2="22"/></svg>
      </div>
      <h3>Register a zone</h3>
      <p>For zonal offices comparing spiritual and financial reports across every church in the zone.</p>
      <ul>
        <li><div class="chk">✓</div> Add as many churches as your zone has</li>
        <li><div class="chk">✓</div> Month-on-month comparison done automatically</li>
        <li><div class="chk">✓</div> Works for any zone, anywhere</li>
      </ul>
      <div class="path-divider"></div>
      <a href="register_zone.php" class="btn btn-outline-dark" style="width:100%;justify-content:center;">Register your zone</a>
    </div>
  </div>
</section>

<!-- ═══ HOW IT WORKS ═════════════════════════════════════ -->
<section class="how">
  <div class="how-inner">
    <div class="section-head">
      <span class="eyebrow">How it works</span>
      <h2><?= ss('how_title', 'From paper form to filed report.') ?></h2>
    </div>
    <div class="how-steps">
      <div class="how-step">
        <div class="step-num">1</div>
        <h4>Register</h4>
        <p>Set up your church or zone in a few minutes no paperwork to mail.</p>
      </div>
      <div class="how-step">
        <div class="step-num">2</div>
        <h4>Fill your report</h4>
        <p>Enter figures for the month. Totals, dues and averages calculate as you go.</p>
      </div>
      <div class="how-step">
        <div class="step-num">3</div>
        <h4>Review &amp; submit</h4>
        <p>Check the numbers, then submit the report locks and stays on file permanently.</p>
      </div>
      <div class="how-step">
        <div class="step-num">4</div>
        <h4>Export &amp; file</h4>
        <p>Download a clean A4 print copy for signatures or hand off to your zonal office.</p>
      </div>
    </div>
  </div>
</section>

<!-- ═══ FOOTER ════════════════════════════════════════════ -->
<footer>
  <div class="footer-inner">
    <div class="footer-brand">
      <img src="assets/logo.jpg" alt="Logo">
      <span>Foursquare Reports</span>
    </div>
    <div class="footer-links">
      <a href="login.php">Log in</a>
      <a href="register_church.php">Register Church</a>
      <a href="register_zone.php">Register Zone</a>
    </div>
    <div class="footer-copy">
      &copy; <?= date('Y') ?> <?= ss('footer_org_name','Foursquare Gospel Church, Isara Zone') ?>
    </div>
  </div>
</footer>


<?php require_once __DIR__ . '/includes/chat_widget.php'; ?>
</body>
</html>

