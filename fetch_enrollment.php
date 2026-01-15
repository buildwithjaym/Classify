<?php
include 'db_connection.php';
$id = $_GET['id'];
$enroll = $conn->query("SELECT * FROM enrollments WHERE id=$id")->fetch_assoc();
?>
<div class="row mb-3">
  <div class="col-md-6">
    <label>Year Level</label>
    <select name="year_level" class="form-select">
      <?php foreach(['1st Year','2nd Year','3rd Year','4th Year'] as $lvl){
        $sel = ($lvl == $enroll['year_level']) ? 'selected' : '';
        echo "<option $sel>$lvl</option>";
      } ?>
    </select>
  </div>
  <div class="col-md-6">
    <label>Semester</label>
    <select name="semester" class="form-select">
      <?php foreach(['1st Semester','2nd Semester','Summer'] as $sem){
        $sel = ($sem == $enroll['semester']) ? 'selected' : '';
        echo "<option $sel>$sem</option>";
      } ?>
    </select>
  </div>
</div>

<div class="row mb-3">
  <div class="col-md-4"><label>Course</label><input name="course" value="<?= $enroll['course'] ?>" class="form-control"></div>
  <div class="col-md-4"><label>Section</label><input name="section" value="<?= $enroll['section'] ?>" class="form-control"></div>
  <div class="col-md-4"><label>Room</label><input name="room" value="<?= $enroll['room'] ?>" class="form-control"></div>
</div>

<div class="text-center mb-3">
  <img src="uploads/<?= $enroll['photo'] ?>" width="100" height="100" style="object-fit:cover;border-radius:10px">
</div>

<div class="mb-3">
  <label>Change Photo</label>
  <input type="file" name="photo" class="form-control">
  <input type="hidden" name="existing_photo" value="<?= $enroll['photo'] ?>">
  <input type="hidden" name="id" value="<?= $id ?>">
</div>

<div class="mb-3">
  <label>Status</label>
  <select name="status" class="form-select">
    <?php foreach(['Enrolled','Dropped','Completed'] as $st){
      $sel = ($st == $enroll['status']) ? 'selected' : '';
      echo "<option $sel>$st</option>";
    } ?>
  </select>
</div>
