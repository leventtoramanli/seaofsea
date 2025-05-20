<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CV - Richard Anderson</title>
  <link href="https://fonts.googleapis.com/css2?family=Open+Sans&display=swap" rel="stylesheet">
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }
    body {
      font-family: 'Open Sans', sans-serif;
      background-color: #f4f4f4;
      padding: 40px;
    }
    .cv-container {
      display: flex;
      max-width: 1000px;
      margin: auto;
      background: white;
      box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
    }
    .left-panel {
      width: 35%;
      background-color: #333;
      color: white;
      padding: 20px;
    }
    .profile-photo {
      width: 100%;
      border-radius: 8px;
      margin-bottom: 5px;
    }
    .sub-header {
      width: 35%;
      background-color: black;
      color: white;
      padding: 20px;
      font-size: 14px;
      font-weight: bold;
      text-transform: uppercase;
      text-align: center;
    }
    .header {
      background: #f4b400;
      color: black;
      padding: 20px;
      width: 65%;
      padding: 0;
      display: flex;
      flex-direction: column;
      font-size: 24px;
      font-weight: bold;
      display: flex;
      text-align: center;
    }
    .section-title {
      font-weight: bold;
      margin: 10px 0 10px;
      text-transform: uppercase;
      font-size: 14px;
      border-bottom: 1px solid #777;
      padding-bottom: 4px;
    }
    .contact-info, .expertise, .education {
      font-size: 13px;
      line-height: 1.6;
    }
    .progress-bar {
      background: #ccc;
      border-radius: 4px;
      overflow: hidden;
      height: 8px;
      margin: 6px 0 14px;
    }
    .progress {
      background: #f4b400;
      height: 100%;
    }
    .right-panel {
      width: 65%;
      padding: 0;
      display: flex;
      flex-direction: column;
    }
    .profile-summary {
      padding: 30px;
    }
    .experience, .references {
      padding: 0 30px 30px 30px;
    }
    .job-title {
      font-weight: bold;
      margin-top: 12px;
    }
    .job-period {
      font-size: 13px;
      color: #888;
    }
    ul {
      padding-left: 20px;
      font-size: 13px;
    }
  </style>
</head>
<body>
  <div class="cv-container">
    <div class="left-panel">
      <img src="https://picsum.photos/536/354" class="profile-photo" alt="Profile Photo" width="200" height="200">
    </div>
    <div class="right-panel">
      <div class="profile-summary">
        <div class="section-title">Profile</div>
        <p>Write a short brief introduction of just a few paragraphs explaining exactly who you are, your strengths and why you feel you are such a suitable candidate.</p>
        <p>Currently looking for a suitable position with a reputable company.</p>
      </div>
    </div>
  </div>
  <div class="cv-container">
        <div class="sub-header">Professional Title</div>
        <div class="header">Richard Anderson</div>
  </div>
  <div class="cv-container">
    <div class="left-panel">
    <div class="section-title">Contact</div>
      <div class="contact-info">
        Dayjob.com, 120 Vyse Street<br>
        Birmingham B18<br>
        0121 638 0026<br>
        info@dayjob.com<br>
        Facebook.com/yourname
      </div>

      <div class="section-title">Expertise</div>
      <div class="expertise">
        <div>MS Word</div>
        <div class="progress-bar"><div class="progress" style="width: 90%"></div></div>
        <div>Teamwork</div>
        <div class="progress-bar"><div class="progress" style="width: 80%"></div></div>
        <div>Communication</div>
        <div class="progress-bar"><div class="progress" style="width: 75%"></div></div>
      </div>

      <div class="section-title">Education</div>
      <div class="education">
        <strong>University name</strong><br>
        2014 – 2017<br>
        Course details / Modules<br><br>
        <strong>College name</strong><br>
        2012 – 2014<br>
        Course details / Subject<br><br>
        <strong>School name</strong><br>
        2008 – 2012<br>
        English (A) Maths (B) Physics (C)
      </div>
    </div>
    <div class="right-panel">
    <div class="profile-summary">
        

      <div class="experience">
        <div class="section-title">Work Experience</div>
        <div class="job-title">Job Title - Company Name</div>
        <div class="job-period">2019 - Present</div>
        <ul>
          <li>Main responsibility</li>
          <li>Key task</li>
          <li>Another contribution</li>
        </ul>

        <div class="job-title">Job Title - Company Name</div>
        <div class="job-period">2016 - 2019</div>
        <ul>
          <li>Main responsibility</li>
          <li>Key task</li>
          <li>Another contribution</li>
        </ul>
      </div>

      <div class="references">
        <div class="section-title">References</div>
        <p>Available on request.</p>
      </div>
    </div>
  </div>
</body>
</html>