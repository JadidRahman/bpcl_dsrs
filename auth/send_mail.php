<?php
function send_verification_email($toEmail, $toName, $otp) {
  $subject = "BPCL DSRS Email Verification Code";

  $body = "
  <div style='font-family:Arial,sans-serif;line-height:1.5'>
    <h2 style='margin:0 0 10px'>Verify your email</h2>
    <p>Hi ".htmlspecialchars($toName).",</p>
    <p>Your verification code is:</p>
    <div style='font-size:28px;font-weight:800;letter-spacing:6px;margin:12px 0'>".htmlspecialchars($otp)."</div>
    <p>This code will expire in <b>10 minutes</b>.</p>
    <p style='color:#666'>If you didn’t request this, you can ignore this email.</p>
  </div>";

  $headers  = "MIME-Version: 1.0\r\n";
  $headers .= "Content-type:text/html;charset=UTF-8\r\n";
  $headers .= "From: BPCL DSRS <no-reply@bpcl.local>\r\n";

  return mail($toEmail, $subject, $body, $headers);
}
