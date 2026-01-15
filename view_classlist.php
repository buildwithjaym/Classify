<?php
$conn = new mysqli("localhost","root","","classify_db");

if (!isset($_GET['subject_id']) || empty($_GET['subject_id'])) {
    die("<h2 style='color:red;'>❌ Error: No subject selected.</h2><a href='admin_dashboard.php'>Go Back</a>");
}

$subject_id = intval($_GET['subject_id']);

$subject = $conn->query("SELECT * FROM subjects WHERE id=$subject_id")->fetch_assoc();

$classlist = $conn->query("
  SELECT e.*, s.photo 
  FROM enrollments e
  LEFT JOIN students s ON e.student_id = s.id
  WHERE e.subject_id = $subject_id
  ORDER BY e.student_name ASC
");

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Classlist - <?= $subject['code'] ?></title>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>

<style>
:root{
    --glass-bg: rgba(255,255,255,0.08);
    --glass-border: rgba(255,255,255,0.25);
    --glass-strong: rgba(255,255,255,0.18);
    --text-main: #fff;
    --accent: #00c6ff;
    --danger: #ff4e50;
    --radius: 16px;
}

/* PAGE BACKGROUND */
body{
    font-family:'Poppins',sans-serif;
    margin:0;
    padding:25px;
    background:linear-gradient(135deg,#0f2027,#203a43,#2c5364);
    color:#fff;
}

/* HEADER */
.page-header{
    opacity:0;
    transform:translateY(-10px);
    animation:fadeDown .7s forwards ease-out;
}
@keyframes fadeDown{
    to{opacity:1;transform:none;}
}

.page-header h2{
    margin:0;
    font-size:1.8rem;
    font-weight:600;
}

/* BUTTONS */
.btn{
    padding:10px 18px;
    background:linear-gradient(135deg,#1e3c72,#2a5298);
    border-radius:var(--radius);
    color:white;
    text-decoration:none;
    display:inline-block;
    margin-right:10px;
    margin-top:12px;
    font-size:.9rem;
    transition:.3s;
    opacity:0;
    animation:fadeUp .7s .2s forwards ease-out;
}

@keyframes fadeUp{
    to{opacity:1;transform:none;}
}

.btn:hover{
    transform:translateY(-3px);
    box-shadow:0 5px 15px rgba(0,0,0,0.3);
}

.btn.export{
    background:linear-gradient(135deg,#11998e,#38ef7d);
}

/* SEARCH BAR */
.search-box{
    margin-top:20px;
    margin-bottom:10px;
    opacity:0;
    animation:fadeUp .7s .3s forwards ease-out;
}

.search-box input{
    width:260px;
    padding:10px 14px;
    border-radius:10px;
    border:none;
    outline:none;
    background:rgba(255,255,255,0.15);
    color:#fff;
    font-size:.9rem;
}

/* TABLE CONTAINER */
.table-wrapper{
    background:var(--glass-bg);
    padding:20px;
    border-radius:var(--radius);
    border:1px solid var(--glass-border);
    backdrop-filter:blur(15px);
    margin-top:20px;
    overflow-x:auto;
    opacity:0;
    animation:fadeUp .7s .4s forwards ease-out;
}

/* TABLE */
table{
    width:100%;
    border-collapse:collapse;
    min-width:850px;
}

th{
    background:rgba(255,255,255,0.15);
    padding:12px;
    font-weight:600;
    text-align:left;
}

td{
    padding:10px;
    border-bottom:1px solid rgba(255,255,255,0.15);
}

tbody tr{
    opacity:0;
    transform:translateY(10px);
    animation:rowFade .5s forwards ease-out;
}
tbody tr:nth-child(n){
    animation-delay: calc(.05s * var(--i));
}

@keyframes rowFade{
    to{opacity:1;transform:none;}
}

/* AVATAR */
img.photo{
    width:40px;
    height:40px;
    object-fit:cover;
    border-radius:50%;
}

/* PAGINATION */
.pagination{
    margin-top:20px;
    text-align:center;
}

.pagination button{
    background:rgba(255,255,255,0.15);
    border:1px solid rgba(255,255,255,0.25);
    color:white;
    padding:8px 14px;
    margin:0 5px;
    border-radius:10px;
    cursor:pointer;
    transition:.3s;
}

.pagination button.active{
    background:var(--accent);
    border-color:var(--accent);
    color:#000;
    font-weight:600;
}

.pagination button:hover{
    transform:scale(1.12);
}

/* LOADER OVERLAY */
.download-loader{
    position:fixed;
    inset:0;
    background:rgba(0,0,0,0.55);
    backdrop-filter:blur(10px);
    display:none;
    justify-content:center;
    align-items:center;
    z-index:9999;
}

.loader-box{
    background:rgba(255,255,255,0.15);
    padding:35px 45px;
    border-radius:20px;
    text-align:center;
    border:1px solid rgba(255,255,255,0.3);
    box-shadow:0 4px 20px rgba(0,0,0,0.4);
}

.spinner{
    width:50px;
    height:50px;
    border:6px solid rgba(255,255,255,0.25);
    border-top-color:var(--accent);
    border-radius:50%;
    animation:spin 1s linear infinite;
    margin:auto;
}

@keyframes spin{to{transform:rotate(360deg);}}

/* SUCCESS CHECKMARK */
.success-check{
    font-size:48px;
    color:#38ef7d;
    display:none;
    animation:pop .4s ease-out;
}
@keyframes pop{
    from{transform:scale(0.5);opacity:0;}
    to{transform:scale(1);opacity:1;}
}

/* RESPONSIVE */
@media(max-width:650px){
    table{min-width:550px;}
    .search-box input{width:100%;}
}
</style>
</head>

<body>

<div class="page-header">
    <h2>Classlist for <?= $subject['code'] ?> - <?= $subject['name'] ?></h2>
</div>

<a class="btn" href="admin_dashboard.php">Back</a>
<button class="btn export" id="downloadPdf">Download PDF</button>
<button class="btn export" id="exportExcel">Export Excel</button>

<div class="search-box">
    <input type="text" id="searchInput" placeholder="🔍 Search student...">
</div>

<div class="table-wrapper">
<table id="classTable">
    <thead>
        <tr>
            <th>Photo</th>
            <th>Name</th>
            <th>Course</th>
            <th>Year</th>
            <th>Semester</th>
            <th>Room</th>
        </tr>
    </thead>

    <tbody id="tableData">
        <?php $i = 1; while ($r = $classlist->fetch_assoc()): ?>
        <tr style="--i:<?= $i++; ?>">
            <td><img src="uploads/students/<?= $r['photo'] ?>" class="photo"></td>
            <td><?= $r['student_name'] ?></td>
            <td><?= $r['course'] ?></td>
            <td><?= $r['year_level'] ?></td>
            <td><?= $r['semester'] ?></td>
            <td><?= $r['room'] ?></td>
        </tr>
        <?php endwhile; ?>
    </tbody>
</table>
</div>

<div class="pagination" id="pagination"></div>

<!-- Loader -->
<div id="downloadLoader" class="download-loader">
  <div class="loader-box">
      <div class="spinner" id="spinner"></div>
      <div class="success-check" id="successCheck">✔</div>
      <p id="loaderText">Preparing your document...</p>
  </div>
</div>

<script>

/* ===========================
   PAGINATION + SEARCH
=========================== */
let rowsPerPage = window.innerWidth < 500 ? 5 : window.innerWidth < 900 ? 8 : 10;

const table = document.getElementById("tableData");
const pagination = document.getElementById("pagination");
const searchInput = document.getElementById("searchInput");

let currentPage = 1;
let filteredRows = Array.from(table.querySelectorAll("tr"));

function renderTable() {
    table.innerHTML = "";
    let start = (currentPage - 1) * rowsPerPage;
    let end = start + rowsPerPage;

    filteredRows.slice(start, end).forEach(r => table.appendChild(r));
    renderPagination();
    window.scrollTo({top:0,behavior:"smooth"});
}

function renderPagination() {
    let pages = Math.ceil(filteredRows.length / rowsPerPage);
    pagination.innerHTML = "";

    let prev = document.createElement("button");
    prev.textContent = "◀ Prev";
    prev.onclick = () => { if(currentPage > 1){ currentPage--; renderTable(); } };
    pagination.appendChild(prev);

    for (let i = 1; i <= pages; i++){
        let btn = document.createElement("button");
        btn.textContent = i;
        if(i === currentPage) btn.classList.add("active");
        btn.onclick = () => { currentPage = i; renderTable(); };
        pagination.appendChild(btn);
    }

    let next = document.createElement("button");
    next.textContent = "Next ▶";
    next.onclick = () => { if(currentPage < pages){ currentPage++; renderTable(); } };
    pagination.appendChild(next);
}

searchInput.addEventListener("input", () => {
    const q = searchInput.value.toLowerCase();
    filteredRows = Array.from(document.querySelectorAll("#classTable tbody tr")).filter(row =>
        row.innerText.toLowerCase().includes(q)
    );
    currentPage = 1;
    renderTable();
});

renderTable();

/* ===========================
   LOADER + SUCCESS ANIMATION
=========================== */
function showLoader(){
    document.getElementById("downloadLoader").style.display = "flex";
    document.getElementById("spinner").style.display = "block";
    document.getElementById("successCheck").style.display = "none";
    document.getElementById("loaderText").innerText = "Preparing your document...";
}

function showSuccess(){
    document.getElementById("spinner").style.display = "none";
    document.getElementById("successCheck").style.display = "block";
    document.getElementById("loaderText").innerText = "Download Complete!";
}

function hideLoader(){
    setTimeout(() => {
        document.getElementById("downloadLoader").style.display = "none";
    }, 1500);
}

/* ===========================
   EXPORT PDF
=========================== */
document.getElementById("downloadPdf").addEventListener("click", () => {
    showLoader();

    setTimeout(() => {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF("landscape");

        doc.setFont("helvetica","bold");
        doc.setFontSize(18);
        doc.text("CLASSIFY - Classlist Report", 150, 15, { align:"center" });

        doc.setFontSize(11);
        doc.text(`Generated: ${new Date().toLocaleString()}`, 150, 23, { align:"center" });

        let data = filteredRows.map(r => {
            let c = r.querySelectorAll("td");
            return [
                c[1].innerText,
                c[2].innerText,
                c[3].innerText,
                c[4].innerText,
                c[5].innerText,
                c[6].innerText
            ];
        });

        doc.autoTable({
            head: [["Name","Course","Year","Semester","Section","Room"]],
            body: data,
            startY:32,
            theme:"grid",
            headStyles:{ fillColor:[32,105,155], textColor:255 },
            styles:{ fontSize:10, halign:"center" }
        });

        doc.save("Classlist.pdf");

        showSuccess();
        hideLoader();
    }, 900);
});

/* ===========================
   EXPORT EXCEL
=========================== */
document.getElementById("exportExcel").addEventListener("click", () => {
    showLoader();

    setTimeout(() => {
        const table = document.getElementById("classTable");
        let clone = table.cloneNode(true);
        clone.querySelectorAll("tr").forEach(row => row.deleteCell(0));

        let html = clone.outerHTML.replace(/ /g, "%20");
        let link = document.createElement("a");

        link.href = "data:application/vnd.ms-excel," + html;
        link.download = "Classlist.xls";
        link.click();

        showSuccess();
        hideLoader();
    }, 900);
});

</script>

</body>
</html>
