<?php
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/adoption-workflow.php';

expireAdoptionReservations($conn);
$petsData = [];
$pq = $conn->query("SELECT * FROM pet_listings WHERE status = 'available' ORDER BY created_at DESC");
if ($pq) {
    while ($row = $pq->fetch_assoc()) $petsData[$row['listing_id']] = $row;
}
$conn->close();
?>
<?php $pageTitle = 'Pet List'; $activePage = 'petlist'; include __DIR__ . '/../../includes/header.php'; ?>
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

.pet-list-section {
    padding: 100px 40px;
    background: #fff;
    color: #18181b;
}
.pet-list-container { width: 100%; max-width: 1320px; margin: 0 auto; }
.pet-list-intro { max-width: 640px; margin-bottom: 28px; }
.pet-list-heading {
    margin: 0 0 12px;
    color: #18181b;
    font-family: 'Inter','Segoe UI',sans-serif;
}
.pet-list-description { margin: 0; color: #71717a; font-size: 15px; line-height: 1.65; }
.pet-list-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 26px; }
.pet-card {
    min-width: 0;
    padding-top: 16px;
    border-top: 1px solid #e4e4e7;
    color: #18181b;
    font-family: 'Inter','Segoe UI',sans-serif;
}
.pet-card[hidden] { display: none; }
.pet-photo {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    aspect-ratio: 1 / 1;
    overflow: hidden;
    border-radius: 3px;
    background: #f4f4f5;
}
.pet-photo img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
.pet-photo-placeholder { width: 44px; height: 44px; color: #a1a1aa; fill: currentColor; }
.pet-card-header { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; margin: 12px 0 3px; }
.pet-card .name { margin: 0; color: #18181b; font-family: 'Inter','Segoe UI',sans-serif; font-size: 20px; font-weight: 600; line-height: 1.3; overflow-wrap: anywhere; }
.pet-card-id { flex: 0 0 auto; color: #71717a; font: 10px/1.4 Consolas, monospace; }
.pet-card-meta { margin: 0 0 8px; color: #71717a; font-size: 13px; line-height: 1.5; }
.pet-card-status { display: inline-flex; align-items: center; gap: 6px; margin: 0 0 12px; color: #50c878; font-size: 12px; }
.pet-card-status::before { width: 7px; height: 7px; border-radius: 50%; background: currentColor; content: ''; }
.pet-care-statuses { display: flex; flex-wrap: wrap; gap: 7px 12px; margin: 0 0 12px; padding: 0; list-style: none; }
.pet-care-statuses li { color: #71717a; font-size: 12px; line-height: 1.4; }
.pet-care-statuses li.is-complete { color: #3f3f46; }
.pet-care-statuses li.is-complete::before { margin-right: 5px; color: #16833b; content: '✓'; font-weight: 700; }
.pet-care-statuses li.is-known:not(.is-complete) { color: #92400e; }
.pet-care-statuses li:not(.is-complete)::before { margin-right: 5px; color: #a1a1aa; content: '○'; }
.pet-care-statuses li.is-known:not(.is-complete)::before { color: #b45309; content: '!'; font-weight: 700; }
.pet-card-facts { display: flex; flex-wrap: wrap; gap: 7px 12px; margin: 0 0 12px; padding: 0; list-style: none; }
.pet-card-facts li { color: #3f3f46; font-size: 12px; line-height: 1.4; }
.pet-card-facts li::before { margin-right: 5px; color: #16833b; content: '✓'; font-weight: 700; }
.pet-card-detail { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.15fr); gap: 6px 10px; margin: 0 0 10px; font-size: 12px; line-height: 1.45; }
.pet-card-detail dt { color: #71717a; }
.pet-card-detail dd { min-width: 0; margin: 0; color: #18181b; overflow-wrap: anywhere; }
.pet-desc { min-height: 38px; margin: 0 0 14px; color: #3f3f46; font: 14px/1.5 'Inter','Segoe UI',sans-serif; overflow-wrap: anywhere; }
.btn-adopt {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    min-height: 40px;
    padding: 8px 14px;
    border: 1px solid #16833b;
    border-radius: 3px;
    background: transparent;
    color: #16833b;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    transition: background .15s ease, color .15s ease;
}
.btn-adopt:hover { background: #16833b; color: #fff; }
.btn-adopt:focus-visible { outline: 2px solid #16833b; outline-offset: 3px; }
.btn-adopt svg { display: none; }
.btn-adopt.btn-disabled { border-color: #d4d4d8; color: #71717a; cursor: default; pointer-events: none; }
.pet-list-empty { grid-column: 1 / -1; padding: 40px 20px; border: 1px dashed #d4d4d8; color: #71717a; text-align: center; }
@media (max-width: 900px) {
    .pet-list-section { padding: 76px 28px 52px; }
    .pet-list-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 24px 18px; }
}
@media (max-width: 600px) {
    .pet-list-section { padding: 60px 18px 44px; }
    .pet-list-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 22px 14px; }
    .pet-card-header { margin-top: 10px; }
    .pet-card .name { font-size: 17px; }
    .pet-card-id { font-size: 8px; }
    .pet-card-meta { font-size: 11px; }
    .pet-card-status, .pet-care-statuses li, .pet-card-detail { font-size: 10px; }
    .pet-care-statuses { gap: 6px 8px; }
    .pet-card-detail { grid-template-columns: minmax(0, 1fr); gap: 2px; }
    .pet-card-detail dt { margin-top: 5px; }
    .pet-desc { min-height: 0; font-size: 12px; }
    .btn-adopt { min-height: 36px; padding: 7px 8px; font-size: 11px; }
}

</style>
    <div class="hero">
        <div class="hero-split">
            <div class="hero-text-col">
                <div class="headings">
                    <h1 class="main-heading">Find Your New Best Friend</h1>
                    <h2 class="secondary-heading">Meet Our Rescued Pets Waiting for a Forever Home</h2>
                    <p class="hero-description">Every pet deserves a loving family. Browse our adoptable pets &mdash; each one is vaccinated, healthy, and ready to fill your home with joy.</p>
                </div>
            </div>
            <div class="hero-image-col">
                <img src="uploads/site/cutout-aspin.png" alt="Happy pet looking for a home">
            </div>
        </div>
        <div class="bg-shape bg-shape-1"></div>
        <div class="bg-shape bg-shape-2"></div>
        <div class="bg-shape bg-shape-3"></div>
    </div>

    <section class="pet-list-section">
        <div class="pet-list-container">
            <header class="pet-list-intro">
                <h1 class="pet-list-heading">Animals available for adoption</h1>
                <p class="pet-list-description">Every animal listed here has been checked in at the DCVS Shelter. Once staff reserve an animal for you, you have 3 days to complete the pickup before it returns to this list.</p>
            </header>
            <div class="pet-list-grid">
                <?php if (empty($petsData)): ?>
                <div class="pet-list-empty">There are no animals currently available for adoption. Please check back soon.</div>
                <?php endif; ?>
                <?php foreach ($petsData as $id => $p): ?>
                <?php
                $petGender = trim((string)($p['gender'] ?? ''));
                $petAge = trim((string)($p['age'] ?? ''));
                $petAgeDisplay = ctype_digit($petAge)
                    ? $petAge . ((int)$petAge === 1 ? ' year old' : ' years old')
                    : $petAge;
                $petBreed = trim((string)($p['breed'] ?? ''));
                $petWeight = trim((string)($p['weight'] ?? ''));
                $vaccinationStatus = $p['vaccination_status'] ?? 'unknown';
                $sterilizationStatus = $p['sterilization_status'] ?? 'unknown';
                $dewormingStatus = $p['deworming_status'] ?? 'unknown';
                $vaccinationLabels = [
                    'vaccinated' => 'Vaccinated',
                    'partially_vaccinated' => 'Partially vaccinated',
                    'not_vaccinated' => 'Not vaccinated',
                    'unknown' => 'Vaccination not recorded',
                ];
                $sterilizationLabels = [
                    'spayed' => 'Spayed',
                    'neutered' => 'Neutered',
                    'not_sterilized' => 'Not yet spayed / neutered',
                    'unknown' => 'Spay / neuter not recorded',
                ];
                $dewormingLabels = [
                    'dewormed' => 'Dewormed',
                    'not_dewormed' => 'Not dewormed',
                    'unknown' => 'Deworming not recorded',
                ];
                $rescuedDateText = trim((string)($p['rescued_date'] ?? ''));
                if ($rescuedDateText !== '' && strtotime($rescuedDateText) !== false) {
                    $rescuedDateText = date('F j, Y', strtotime($rescuedDateText));
                }
                ?>
                <article class="pet-card"
                    data-gender="<?php echo htmlspecialchars(strtolower($petGender)); ?>"
                    data-age="<?php echo htmlspecialchars(strtolower($petAge)); ?>"
                    data-breed="<?php echo htmlspecialchars(strtolower($petBreed)); ?>">
                    <div class="pet-photo">
                        <svg class="pet-photo-placeholder" viewBox="0 0 48 48" aria-hidden="true">
                            <ellipse cx="12" cy="15" rx="4" ry="6"/><ellipse cx="22" cy="10" rx="4" ry="6"/>
                            <ellipse cx="32" cy="11" rx="4" ry="6"/><ellipse cx="40" cy="18" rx="4" ry="6"/>
                            <path d="M24 21c-6 0-15 9-14 15 .7 4 5 5 9 3 3-1 7-1 10 0 5 2 9 1 10-3 1-6-8-15-15-15z"/>
                        </svg>
                        <?php if (!empty($p['photo_url'])): ?>
                        <img src="<?php echo htmlspecialchars($p['photo_url']); ?>" alt="<?php echo htmlspecialchars($p['name']); ?>" loading="lazy" onerror="this.remove()">
                        <?php endif; ?>
                    </div>
                    <div class="pet-card-header">
                        <h2 class="name"><?php echo htmlspecialchars($p['name']); ?></h2>
                    </div>
                    <p class="pet-card-meta"><?php echo htmlspecialchars(trim(($petGender !== '' ? ucfirst($petGender) . ' ' : '') . ($petBreed !== '' ? $petBreed : 'Unknown breed') . ($petAgeDisplay !== '' ? ', ' . $petAgeDisplay : ''))); ?></p>
                    <p class="pet-card-status">Available</p>
                    <ul class="pet-care-statuses" aria-label="Health and care information">
                        <li class="<?php echo $vaccinationStatus === 'vaccinated' ? 'is-complete' : ($vaccinationStatus !== 'unknown' ? 'is-known' : ''); ?>"><?php echo htmlspecialchars($vaccinationLabels[$vaccinationStatus] ?? $vaccinationLabels['unknown']); ?></li>
                        <li class="<?php echo in_array($sterilizationStatus, ['spayed', 'neutered'], true) ? 'is-complete' : ($sterilizationStatus !== 'unknown' ? 'is-known' : ''); ?>"><?php echo htmlspecialchars($sterilizationLabels[$sterilizationStatus] ?? $sterilizationLabels['unknown']); ?></li>
                        <li class="<?php echo $dewormingStatus === 'dewormed' ? 'is-complete' : ($dewormingStatus !== 'unknown' ? 'is-known' : ''); ?>"><?php echo htmlspecialchars($dewormingLabels[$dewormingStatus] ?? $dewormingLabels['unknown']); ?></li>
                    </ul>
                    <dl class="pet-card-detail">
                        <dt>Temperament</dt><dd><?php echo htmlspecialchars(trim((string)($p['temperament'] ?? '')) ?: 'Not recorded'); ?></dd>
                        <dt>At the shelter since</dt><dd><?php echo htmlspecialchars($rescuedDateText !== '' ? $rescuedDateText : 'Not recorded'); ?></dd>
                        <?php if ($petWeight !== '' && $petWeight !== '0'): ?>
                        <dt>Weight</dt><dd><?php echo htmlspecialchars($petWeight . ' kg'); ?></dd>
                        <?php endif; ?>
                        <?php if (trim((string)($p['vaccinations'] ?? '')) !== ''): ?>
                        <dt>Vaccination details</dt><dd><?php echo htmlspecialchars($p['vaccinations']); ?></dd>
                        <?php endif; ?>
                    </dl>
                    <p class="pet-desc"><?php echo htmlspecialchars($p['description'] ?: 'No description available.'); ?></p>
                    <?php if ($p['status'] === 'available'): ?>
                    <a href="adopt-process.php?listing_id=<?php echo (int)$id; ?>" class="btn-adopt">Adopt Me</a>
                    <?php else: ?>
                    <div class="btn-adopt btn-disabled"><?php echo htmlspecialchars(ucfirst($p['status'])); ?></div>
                    <?php endif; ?>
                </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
</body>
</html>
