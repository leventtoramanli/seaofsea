@echo off
REM Git Auto Commit and Push Script
REM Klasör: C:\xampp\htdocs\seaofsea

REM Proje klasörüne geç
cd /d C:\xampp\htdocs\seaofsea

REM Git işlemleri
git reset
git add .
git commit -m "Auto-sync: %date% %time%"
git push origin main

REM İşlem tamamlandı mesajı
echo ------------------------------------
echo Git Auto Commit and Push Tamamlandı!
echo ------------------------------------
pause
