<!-- Add this button in the actions section -->
<div class="detail-card">
    <div class="section-title">Court Case Actions</div>
    <button class="btn btn-primary" onclick="fetchCourtCases()">
        <i class="fas fa-search"></i> Find Court Cases
    </button>
    <div id="court-cases-results"></div>
</div>

<script>
async function fetchCourtCases() {
    const inmateId = '<?= htmlspecialchars($inmate['inmate_id']) ?>';
    const firstName = '<?= htmlspecialchars($inmate['first_name']) ?>'; 
    const lastName = '<?= htmlspecialchars($inmate['last_name']) ?>';
    
    try {
        const response = await fetch('fetch_court_cases.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                inmate_id: inmateId,
                first_name: firstName,
                last_name: lastName
            })
        });
        
        if (response.ok) {
            const data = await response.json();
            displayCourtCases(data);
        }
    } catch (error) {
        console.error('Error:', error);
    }
}

function displayCourtCases(cases) {
    const resultsDiv = document.getElementById('court-cases-results');
    if (!cases || cases.length === 0) {
        resultsDiv.innerHTML = '<div class="alert alert-info">No court cases found</div>';
        return;
    }
    
    let html = '<div class="court-cases-list">';
    cases.forEach(case => {
        html += `
            <div class="court-case-item">
                <h4>${case.case_number}</h4>
                <p>Filed: ${case.filing_date}</p>
                <p>Status: ${case.case_status}</p>
                <a href="case_detail.php?case=${case.case_number}" class="btn btn-secondary">View Details</a>
            </div>
        `;
    });
    html += '</div>';
    
    resultsDiv.innerHTML = html;
}
</script>