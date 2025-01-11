document.addEventListener("DOMContentLoaded", () => {
    const form = document.getElementById("passwordResetForm");
    const newPasswordInput = document.getElementById("new_password");
    const confirmPasswordInput = document.getElementById("confirm_password");

    form.addEventListener("submit", (event) => {
        let isValid = true;

        // Yeni şifre kontrolü
        if (newPasswordInput.value.length < 6) {
            showError(newPasswordInput, "Password must be at least 6 characters long.");
            isValid = false;
        } else {
            clearError(newPasswordInput);
        }

        // Şifre doğrulama kontrolü
        if (newPasswordInput.value !== confirmPasswordInput.value) {
            showError(confirmPasswordInput, "Passwords do not match.");
            isValid = false;
        } else {
            clearError(confirmPasswordInput);
        }

        // Form gönderimini engelle
        if (!isValid) {
            event.preventDefault();
        }
    });

    // Hata mesajı göster
    function showError(input, message) {
        const errorElement = input.parentElement.querySelector(".error-message");
        errorElement.textContent = message;
        errorElement.style.display = "block";
        input.style.borderColor = "#dc3545";
    }

    // Hata mesajını temizle
    function clearError(input) {
        const errorElement = input.parentElement.querySelector(".error-message");
        errorElement.textContent = "";
        errorElement.style.display = "none";
        input.style.borderColor = "#ddd";
    }
});
