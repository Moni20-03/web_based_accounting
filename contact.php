<?php
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = htmlspecialchars($_POST["name"]);
    $email = htmlspecialchars($_POST["email"]);
    $message = htmlspecialchars($_POST["message"]);

    $to = "monisha98940@gmail.com"; // Replace with the official Finpack email
    $subject = "New Contact Form Message from " . $name;
    $headers = "From: " . $email . "\r\n" .
               "Reply-To: " . $email . "\r\n" .
               "Content-Type: text/plain; charset=UTF-8\r\n";

    $fullMessage = "Name: " . $name . "\n";
    $fullMessage .= "Email: " . $email . "\n";
    $fullMessage .= "Message:\n" . $message;

    // Send Email
    if (mail($to, $subject, $fullMessage, $headers)) {
        echo "<script>alert('Message sent successfully!'); window.location.href='index.html';</script>";
    } else {
        echo "<script>alert('Failed to send message. Please try again.'); window.location.href='index.html';</script>";
    }
} else {
    header("Location: index.html");
    exit();
}
?>
