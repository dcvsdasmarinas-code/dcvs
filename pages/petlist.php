<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/adoption-workflow.php';

adoptionReleaseExpiredReservations($conn);
$petsData = [];
$pq = $conn->query("SELECT * FROM pet_listings WHERE status = 'available' ORDER BY created_at DESC");
if ($pq) {
    while ($row = $pq->fetch_assoc()) $petsData[$row['listing_id']] = $row;
}
$conn->close();
?>
<?php $pageTitle = 'Pet List'; $activePage = 'petlist'; include 'includes/header.php'; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&family=Open+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Inter','Segoe UI',sans-serif;
}

.hero {
    min-height: 80vh;
    background: white;
    position: relative;
    display: flex;
    align-items: center;
    padding: 120px 40px 80px;
}

.hero-split {
    display: grid;
    grid-template-columns: 1fr 1fr;
    width: min(1400px, 92vw);
    margin: 0 auto;
    align-items: center;
    gap: 60px;
    position: relative;
    z-index: 10;
}

.hero-text-col {
    display: flex;
    flex-direction: column;
    gap: 0;
}

.hero-text-col .headings {
    text-align: left;
    margin: 0;
}

.hero-text-col .main-heading {
    max-width: 14ch;
}

.hero-text-col .secondary-heading {
    margin: 0 0 20px;
}

.hero-text-col .hero-description {
    margin: 0;
}

.hero-image-col {
    position: relative;
    height: 520px;
}

.hero-image-col img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    display: block;
    background: transparent !important;
}



/* Main Headings */
.headings {
    text-align: center;
    margin-top: 40px;
    margin-bottom: 100px;
}

.main-heading {
    font-size: clamp(40px, 5.5vw, 76px) !important;
    font-weight: 600;
    color: #18181b;
    line-height: 1.1;
    margin-bottom: 16px;
    letter-spacing: -0.025em;
    font-family: 'Inter','Segoe UI',sans-serif;
}

.secondary-heading {
    font-size: 24px !important;
    font-weight: 500;
    color: #71717a;
    line-height: 1.5;
    max-width: 650px;
    margin: 0 auto 20px;
    font-family: 'Inter','Segoe UI',sans-serif;
}

.hero-description {
    font-size: 18px;
    line-height: 1.5;
    color: #71717a;
    max-width: 600px;
    margin: 0 auto;
    font-family: 'Inter','Segoe UI',sans-serif;
}

/* Decorative Background Shapes */
.bg-shape {
    position: absolute;
    border: 2px solid rgba(34, 197, 94, 0.15);
    border-radius: 60% 40% 30% 70% / 60% 30% 70% 40%;
    pointer-events: none;
    z-index: 0;
}

.bg-shape-1 {
    width: 350px;
    height: 280px;
    top: 15%;
    left: 3%;
    opacity: 0.25;
    transform: rotate(-15deg);
}

.bg-shape-2 {
    width: 450px;
    height: 380px;
    top: 35%;
    right: 5%;
    opacity: 0.18;
    transform: rotate(20deg);
    border-radius: 40% 60% 70% 30% / 40% 50% 60% 50%;
}

.bg-shape-3 {
    width: 300px;
    height: 250px;
    bottom: 10%;
    left: 25%;
    opacity: 0.2;
    transform: rotate(-10deg);
    border-radius: 50% 50% 30% 70% / 50% 70% 30% 50%;
}



/* Responsive Design */
@media (max-width: 1200px) {
    .main-heading {
        font-size: 76px;
        letter-spacing: -0.02em;
    }
    
    .secondary-heading {
        font-size: 22px !important;
    }
}

@media (max-width: 1024px) {
    .hero-split {
        gap: 40px;
    }
    .hero-image-col {
        height: 400px;
    }
    .hero-text-col .main-heading {
        font-size: 60px;
        letter-spacing: -0.02em;
    }
    .hero-text-col .secondary-heading {
        font-size: 20px !important;
    }
    .hero-text-col .hero-description {
        font-size: 17px;
    }
}

@media (max-width: 768px) {
    .hero {
        min-height: auto;
        padding: 100px 20px 60px;
    }
    .hero-split {
        grid-template-columns: 1fr;
        gap: 40px;
    }
    .hero-text-col .headings {
        text-align: center;
    }
    .hero-text-col .main-heading {
        max-width: 100%;
        font-size: 48px;
        letter-spacing: -0.02em;
    }
    .hero-text-col .secondary-heading {
        font-size: 18px !important;
        margin: 0 auto 20px;
    }
    .hero-text-col .hero-description {
        margin: 0 auto;
        font-size: 16px;
    }
    .hero-image-col {
        height: 350px;
        order: -1;
    }
    .bg-shape {
        display: none;
    }
}

@media (max-width: 480px) {
    .hero-text-col .main-heading {
        font-size: 38px;
        letter-spacing: -0.02em;
    }
    .hero-text-col .secondary-heading {
        font-size: 16px !important;
    }
    .hero-text-col .hero-description {
        font-size: 15px;
    }
    .hero-image-col {
        height: 280px;
    }
}

/* Pet List Section */
.pet-list-section {
    padding: 100px 40px 100px;
    background: white;
}

.pet-list-container {
    width: 100%;
    max-width: 1500px;
    min-width: 0;
    margin: 0 auto;
}

.pet-list-heading {
    font-size: 32px;
    font-weight: 600;
    color: #18181b;
    text-align: center;
    margin-bottom: 40px;
    letter-spacing: -0.025em;
    font-family: 'Inter','Segoe UI',sans-serif;
}

/* Card grid */
.pet-list-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 32px;
    min-width: 0;
}

.pet-card {
    position: relative;
    min-width: 0;
    background: transparent;
    border: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0;
    font-family: 'Inter','Segoe UI',sans-serif;
}

.pet-card .photo {
    width: calc(100% - 36px);
    aspect-ratio: 1 / 1;
    overflow: hidden;
    position: relative;
    background: #f4f4f5;
    margin: 0 auto;
    border: 3px solid #18181b;
    border-radius: 4px;
    z-index: 2;
}

.pet-card .photo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.pet-card .photo img.blurred {
    filter: blur(3px) brightness(0.6);
}

.pet-status-overlay {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    z-index: 5;
    pointer-events: none;
    display: flex;
    align-items: center;
    justify-content: center;
}

.pet-status-text {
    color: #fff;
    font-size: 24px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 2px;
    font-family: 'Inter','Segoe UI',sans-serif;
    text-shadow: 0 2px 12px rgba(0,0,0,0.7);
}

.pet-desc {
    font-family: 'Inter','Segoe UI',sans-serif;
    font-size: 18px;
    font-weight: 400;
    color: #18181b;
    line-height: 1.35;
    text-align: center;
    padding: 8px 0 22px;
}

.pet-card-details {
    padding: 10px 0 0;
}

.pet-card-body {
    position: relative;
    z-index: 1;
    margin-top: -44%;
    padding: calc(44% + 20px) 18px 18px;
    background: #fff;
    border: 0;
    border-radius: 10px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
}

.pet-card-header {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    margin-bottom: 8px;
}

.pet-card .name {
    font-family: 'Inter','Segoe UI',sans-serif;
    font-size: 26px;
    font-weight: 700;
    color: #09090b;
    line-height: 1.3;
    text-align: center;
}

.pet-card-meta {
    font-family: 'Inter','Segoe UI',sans-serif;
    color: #18181b;
    line-height: 1.3;
    margin-top: 40px;
    margin-bottom: 42px;
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 26px 18px;
    align-items: center;
}

.pet-card-meta span {
    display: inline;
}

.pet-card-meta > div {
    min-width: 0;
    white-space: nowrap;
}

.pet-card-meta > div:nth-child(2),
.pet-card-meta > div:nth-child(4) {
    justify-self: start;
}

.pet-card-meta .meta-label {
    font-size: 16px;
    font-weight: 700;
    margin-right: 4px;
}

.pet-card-meta .meta-value {
    font-size: 16px;
    font-weight: 400;
    white-space: nowrap;
}

.btn-adopt {
    align-self: center;
    width: calc(100% - 36px);
    max-width: 280px;
    margin: -22px auto -22px;
    position: relative;
    z-index: 3;
    display: inline-flex;
    min-height: 44px;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 22px;
    border: 0;
    border-radius: 6px;
    font-family: 'Inter','Segoe UI',sans-serif;
    font-size: 16px;
    font-weight: 500;
    color: #fafafa;
    background: #18181b;
    text-decoration: none;
    cursor: pointer;
    box-shadow: none;
    transition: background 0.15s, box-shadow 0.15s;
}

.btn-adopt:hover {
    background: #27272a;
    box-shadow: none;
}

.btn-adopt:focus-visible {
    outline: 2px solid #18181b;
    outline-offset: 2px;
}

.btn-adopt svg {
    display: none;
}

.btn-adopt.btn-disabled {
    background: #a1a1aa;
    color: #fafafa;
    cursor: default;
    pointer-events: none;
}

.btn-adopt.btn-disabled:hover {
    background: #a1a1aa;
}

/* Pet List Responsive */
@media (max-width: 1100px) {
    .pet-list-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 720px) {
    .pet-list-section {
        padding: 60px 20px;
    }

    .pet-list-heading {
        font-size: 24px;
        margin-bottom: 30px;
    }
    
    .pet-list-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 16px;
    }
}

@media (max-width: 480px) {
    .pet-list-section {
        padding: 44px 16px 56px;
    }

    .pet-list-grid {
        grid-template-columns: minmax(0, 1fr);
        gap: 22px;
    }

    .pet-card .photo {
        width: min(76%, 240px);
    }

    .pet-card-body {
        margin-top: -30%;
        padding: calc(30% + 16px) 14px 16px;
    }

    .pet-card .name {
        font-size: 22px;
        overflow-wrap: anywhere;
    }

    .pet-card-meta {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px 10px;
        margin-top: 20px;
        margin-bottom: 24px;
    }

    .pet-card-meta > div {
        min-width: 0;
        white-space: normal;
        overflow-wrap: anywhere;
    }

    .pet-card-meta .meta-label,
    .pet-card-meta .meta-value {
        font-size: 13px;
        line-height: 1.4;
        white-space: normal;
        overflow-wrap: anywhere;
    }

    .pet-desc {
        font-size: 14px;
        line-height: 1.5;
        padding: 0 0 14px;
        overflow-wrap: anywhere;
    }
    .pet-card-details {
        padding-top: 12px;
    }
    .btn-adopt {
        width: min(80%, 240px);
        min-height: 40px;
        margin: 8px auto 0;
        padding: 8px 12px;
        font-size: 13px;
    }
    .pet-status-text {
        font-size: 16px;
        letter-spacing: 1px;
    }
}

</style>
    <div class="hero">
        <div class="hero-split">
            <div class="hero-text-col">
                <div class="headings">
                    <h1 class="main-heading">Find Your New Best Friend</h1>
                    <h2 class="secondary-heading">Meet Our Rescued Pets Waiting for a Forever Home</h2>
                    <p class="hero-description">Every pet deserves a loving family. Browse our adoptable pets â€” each one is vaccinated, healthy, and ready to fill your home with joy.</p>
                </div>
            </div>
            <div class="hero-image-col">
                <img src="uploads/site/cutout-aspin.png" alt="Happy pet looking for a home">
            </div>
        </div>

        <!-- Decorative Background Shapes -->
        <div class="bg-shape bg-shape-1"></div>
        <div class="bg-shape bg-shape-2"></div>
        <div class="bg-shape bg-shape-3"></div>
    </div>

    <!-- Pet List Section -->
    <section class="pet-list-section">
        <div class="pet-list-container">
            <h2 class="pet-list-heading">Our Pets</h2>
            <div class="pet-list-grid">
                <?php foreach ($petsData as $id => $p): ?>
                <div class="pet-card">
                    <div class="photo">
                        <img src="<?php echo htmlspecialchars($p['photo_url'] ?? ''); ?>" alt="<?php echo htmlspecialchars($p['name']); ?>" class="<?php echo $p['status'] !== 'available' ? 'blurred' : ''; ?>">
                        <?php if ($p['status'] !== 'available'): ?>
                        <div class="pet-status-overlay">
                            <span class="pet-status-text"><?php echo htmlspecialchars(ucfirst($p['status'])); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="pet-card-body">
                    <div class="pet-card-details">
                        <div class="pet-card-header">
                            <div class="name"><?php echo htmlspecialchars($p['name']); ?></div>
                        </div>
                        <div class="pet-card-meta">
                            <?php
                            $rescuedDateText = trim((string)($p['rescued_date'] ?? ''));
                            if ($rescuedDateText !== '' && strtotime($rescuedDateText) !== false) {
                                $rescuedDateText = date('F j', strtotime($rescuedDateText));
                            }
                            $ageText = trim((string)($p['age'] ?? ''));
                            if ($ageText !== '' && strcasecmp($ageText, 'unknown') !== 0) {
                                $ageText = trim((string)preg_replace('/\s*(years?|yrs?|months?|mos?|old)\b/i', '', $ageText));
                            }
                            ?>
                            <div>
                                <span class="meta-label">Breed:</span>
                                <span class="meta-value"><?php echo htmlspecialchars($p['breed'] ?: 'Unknown'); ?></span>
                            </div>
                            <div>
                                <span class="meta-label">Gender:</span>
                                <span class="meta-value"><?php echo htmlspecialchars($p['gender'] ?: 'Unknown'); ?></span>
                            </div>
                            <div>
                                <span class="meta-label">Age:</span>
                                <span class="meta-value"><?php echo htmlspecialchars($ageText ?: 'Unknown'); ?></span>
                            </div>
                            <div>
                                <span class="meta-label">Rescued:</span>
                                <span class="meta-value"><?php echo htmlspecialchars($rescuedDateText ?: 'Unknown'); ?></span>
                            </div>
                        </div>
                        <div class="pet-desc"><?php echo htmlspecialchars($p['description'] ?: 'No description available.'); ?></div>
                    </div>
                    </div>
                    <?php if ($p['status'] === 'available'): ?>
                    <a href="adopt-process.php?listing_id=<?php echo $id; ?>" class="btn-adopt">
                        Adopt Me
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                    </a>
                    <?php else: ?>
                    <div class="btn-adopt btn-disabled">
                        <?php echo $p['status'] === 'adopted' ? 'Already Adopted' : 'Not Available'; ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

<?php include 'includes/footer.php'; ?>
</body>
</html>
