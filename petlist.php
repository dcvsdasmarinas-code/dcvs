<?php
require_once 'db.php';

$petsData = [];
$pq = $conn->query("SELECT * FROM pet_listings ORDER BY created_at DESC");
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
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    overflow-x: hidden;
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
    font-size: 88px;
    font-weight: 800;
    color: #045e1f;
    line-height: 0.95;
    margin-bottom: 24px;
    letter-spacing: -4px;
    font-family: 'Montserrat', sans-serif;
}

.secondary-heading {
    font-size: 25px !important;
    font-weight: 800;
    color: #045e1f;
    line-height: 1.35;
    max-width: 650px;
    margin: 0 auto 20px;
    font-family: 'Montserrat', sans-serif;
}

.hero-description {
    font-size: 1.125rem;
    line-height: 1.7;
    color: #212121;
    max-width: 600px;
    margin: 0 auto;
    font-family: 'Open Sans', sans-serif;
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
        font-size: 64px;
        letter-spacing: -3px;
    }
    
    .secondary-heading {
        font-size: 25px !important;
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
        font-size: 52px;
        letter-spacing: -2px;
    }
    .hero-text-col .secondary-heading {
        font-size: 25px !important;
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
        font-size: 42px;
        letter-spacing: -1.5px;
    }
    .hero-text-col .secondary-heading {
        font-size: 21px !important;
        margin: 0 auto 20px;
    }
    .hero-text-col .hero-description {
        margin: 0 auto;
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
        font-size: 32px;
        letter-spacing: -1px;
    }
    .hero-text-col .secondary-heading {
        font-size: 19px !important;
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
    max-width: 1500px;
    margin: 0 auto;
}

.pet-list-heading {
    font-size: 48px;
    font-weight: 800;
    color: #045e1f;
    text-align: center;
    margin-bottom: 60px;
    letter-spacing: -2px;
    font-family: 'Montserrat', sans-serif;
}

/* Card grid */
.pet-list-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 32px;
}

.pet-card {
    position: relative;
    background: #fff;
    border: 1px solid #e2e8f0;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.pet-card .photo {
    width: 100%;
    aspect-ratio: 5 / 4;
    overflow: hidden;
    position: relative;
    background: #f0fdf4;
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
    font-size: 28px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 4px;
    font-family: 'Montserrat', sans-serif;
    text-shadow: 0 2px 12px rgba(0,0,0,0.7);
}

.pet-card .info {
    padding: 22px 24px 4px;
}

.pet-card .name {
    font-size: 29px;
    font-weight: 800;
    color: #1a1a1a;
    margin-bottom: 12px;
}

.pet-facts {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px 14px;
    margin-bottom: 16px;
}

.pet-fact {
    font-size: 16px;
    font-weight: 600;
    color: #2b2b2b;
    line-height: 1.4;
}

.pet-fact .label {
    display: block;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #7d7d7d;
    font-weight: 700;
    margin-bottom: 2px;
}

.pet-desc {
    font-size: 16px;
    color: #3c3f42;
    line-height: 1.6;
    padding: 0 24px 18px;
    border-top: 1px solid #eee;
    margin-top: 2px;
    padding-top: 14px;
}

.btn-adopt {
    margin: 0 24px 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    background: #1a1a1a;
    color: #fff;
    font-size: 16.5px;
    font-weight: 700;
    padding: 13px;
    border-radius: 8px;
    text-decoration: none;
    transition: background 0.15s;
    border: none;
    cursor: pointer;
}

.btn-adopt:hover {
    background: #000000;
}

.btn-adopt svg {
    width: 18px;
    height: 18px;
    stroke: #fff;
}

.btn-adopt.btn-disabled {
    background: #94a3b8;
    cursor: default;
    pointer-events: none;
}

.btn-adopt.btn-disabled:hover {
    background: #94a3b8;
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
        font-size: 36px;
        margin-bottom: 40px;
    }
    
    .pet-list-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 16px;
    }
}

@media (max-width: 480px) {
    .pet-card .name {
        font-size: 15px;
        margin-bottom: 6px;
    }
    .pet-card .info {
        padding: 10px 10px 0;
    }
    .pet-facts {
        grid-template-columns: 1fr 1fr;
        gap: 4px 8px;
        margin-bottom: 8px;
    }
    .pet-fact {
        font-size: 11px;
        line-height: 1.3;
    }
    .pet-fact .label {
        font-size: 9px;
        margin-bottom: 1px;
    }
    .pet-desc {
        font-size: 11.5px;
        line-height: 1.45;
        padding: 0 10px 8px;
    }
    .btn-adopt {
        margin: 0 10px 10px;
        padding: 9px;
        font-size: 12px;
    }
    .pet-status-text {
        font-size: 15px;
        letter-spacing: 2px;
    }
    .pet-card .photo {
        aspect-ratio: 1 / 1;
    }
}
</style>
    <div class="hero">
        <div class="hero-split">
            <div class="hero-text-col">
                <div class="headings">
                    <h1 class="main-heading">Find Your New Best Friend</h1>
                    <h2 class="secondary-heading">Meet Our Rescued Pets Waiting for a Forever Home</h2>
                    <p class="hero-description">Every pet deserves a loving family. Browse our adoptable pets — each one is vaccinated, healthy, and ready to fill your home with joy.</p>
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
                    <div class="info">
                        <div class="name"><?php echo htmlspecialchars($p['name']); ?></div>
                        <div class="pet-facts">
                            <div class="pet-fact"><span class="label">Breed</span><?php echo htmlspecialchars($p['breed'] ?? '-'); ?></div>
                            <div class="pet-fact"><span class="label">Age</span><?php echo htmlspecialchars($p['age'] ?? '-'); ?></div>
                            <div class="pet-fact"><span class="label">Weight</span><?php echo htmlspecialchars($p['weight'] ?? '-'); ?></div>
                            <div class="pet-fact"><span class="label">Color</span><?php echo htmlspecialchars($p['color'] ?? '-'); ?></div>
                            <div class="pet-fact"><span class="label">Gender</span><?php echo htmlspecialchars($p['gender'] ?? '-'); ?></div>
                            <div class="pet-fact"><span class="label">Rescued</span><?php echo htmlspecialchars($p['rescued_date'] ?? '-'); ?></div>
                        </div>
                    </div>
                    <div class="pet-desc"><?php echo htmlspecialchars($p['description'] ?? ''); ?></div>
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
