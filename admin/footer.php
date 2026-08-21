<?php
// admin/footer.php - Admin Footer Component
?>
    </main>
    <footer>
        TeamTrace Lightweight Employee Monitoring System &bull; Transparent Workplace Authorization Only &bull; PHP 8.2+
    </footer>
    <script>
        // Modal image viewer function
        function openImageModal(src, title) {
            let modal = document.getElementById('imageModal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'imageModal';
                modal.className = 'modal-backdrop';
                modal.innerHTML = `
                    <div class="modal-content">
                        <h4 id="modalTitle" style="margin-bottom:0.75rem; color:var(--accent-blue)"></h4>
                        <img id="modalImg" class="modal-image" src="" alt="Screenshot">
                        <button class="btn btn-secondary" onclick="closeImageModal()">Close</button>
                    </div>
                `;
                document.body.appendChild(modal);
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) closeImageModal();
                });
            }
            document.getElementById('modalImg').src = src;
            document.getElementById('modalTitle').textContent = title || 'Screenshot View';
            modal.classList.add('active');
        }

        function closeImageModal() {
            const modal = document.getElementById('imageModal');
            if (modal) modal.classList.remove('active');
        }
    </script>
</body>
</html>
