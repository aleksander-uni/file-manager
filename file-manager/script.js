class FileManager {
    constructor() {
        this.currentPath = '';
        this.cutItems = []; // Элементы для перемещения
        this.selectedItems = new Set(); // Выбранные элементы
        this.init();
    }

    init() {
        this.bindEvents();
        this.loadFiles();
        this.updatePasteButton(); // Initialize paste button state
    }

    bindEvents() {
        // Upload button
        document.getElementById('uploadBtn').addEventListener('click', () => {
            this.toggleUploadArea();
        });

        // File input
        document.getElementById('fileInput').addEventListener('change', (e) => {
            this.handleFileSelect(e.target.files);
        });

        // Drag and drop
        const dropZone = document.getElementById('dropZone');
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('dragover');
        });

        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('dragover');
        });

        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
            this.handleFileSelect(e.dataTransfer.files);
        });

        // Create folder
        document.getElementById('createFolderBtn').addEventListener('click', () => {
            this.showCreateFolderModal();
        });

        document.getElementById('confirmCreateFolder').addEventListener('click', () => {
            this.createFolder();
        });

        document.getElementById('cancelCreateFolder').addEventListener('click', () => {
            this.hideCreateFolderModal();
        });

        // Create archive
        document.getElementById('createArchiveBtn').addEventListener('click', () => {
            this.showCreateArchiveModal();
        });

        document.getElementById('confirmCreateArchive').addEventListener('click', () => {
            this.createArchive();
        });

        document.getElementById('cancelCreateArchive').addEventListener('click', () => {
            this.hideCreateArchiveModal();
        });

        // Delete modal
        document.getElementById('confirmDelete').addEventListener('click', () => {
            this.confirmDelete();
        });

        document.getElementById('cancelDelete').addEventListener('click', () => {
            this.hideDeleteModal();
        });

        // Refresh button
        document.getElementById('refreshBtn').addEventListener('click', () => {
            this.loadFiles();
        });



        // Move functionality
        document.addEventListener('keydown', (e) => {
            // Ctrl+X for cut
            if (e.ctrlKey && e.key === 'x') {
                this.cutSelectedItems();
            }
            // Ctrl+V for paste
            if (e.ctrlKey && e.key === 'v') {
                this.pasteItems();
            }
        });

        // Close modals on outside click
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('modal')) {
                e.target.classList.add('hidden');
            }
        });

        // Enter key for folder creation
        document.getElementById('folderNameInput').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                this.createFolder();
            }
        });

        // Enter key for archive creation
        document.getElementById('archiveNameInput').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                this.createArchive();
            }
        });
    }

    toggleUploadArea() {
        const uploadArea = document.getElementById('uploadArea');
        uploadArea.classList.toggle('hidden');
    }

    async loadFiles() {
        try {
            const response = await fetch(`php/list_files.php?path=${encodeURIComponent(this.currentPath)}`);
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('Non-JSON response from list_files.php:', text);
                throw new Error(`Сервер вернул неожиданный ответ. Проверьте консоль для деталей.`);
            }

            const data = await response.json();
            
            if (data.success) {
                this.renderFileList(data.files);
                this.updateBreadcrumb();
            } else {
                this.showNotification('Ошибка загрузки файлов: ' + data.error, 'error');
            }
        } catch (error) {
            console.error('Error loading files:', error);
            if (error.name === 'SyntaxError' && error.message.includes('JSON')) {
                this.showNotification('Ошибка формата ответа сервера. Проверьте консоль браузера.', 'error');
            } else {
                this.showNotification('Ошибка соединения с сервером: ' + error.message, 'error');
            }
        }
    }

    renderFileList(files) {
        const container = document.getElementById('fileListContent');
        container.innerHTML = '';

        // Add parent directory link if not in root
        if (this.currentPath !== '') {
            const parentItem = this.createFileItem({
                name: '..',
                type: 'directory',
                size: '',
                modified: '',
                isParent: true
            });
            container.appendChild(parentItem);
        }

        // Sort files: directories first, then files (with proper Cyrillic support)
        files.sort((a, b) => {
            if (a.type !== b.type) {
                return a.type === 'directory' ? -1 : 1;
            }
            // Use localeCompare with Russian locale for proper Cyrillic sorting
            return a.name.localeCompare(b.name, 'ru', { 
                numeric: true, 
                sensitivity: 'base',
                ignorePunctuation: true 
            });
        });

        files.forEach(file => {
            const fileItem = this.createFileItem(file);
            container.appendChild(fileItem);
        });
    }

    createFileItem(file) {
        const item = document.createElement('div');
        item.className = 'file-item';
        item.dataset.fileName = file.name;
        item.dataset.fileType = file.type;

        const isDirectory = file.type === 'directory';
        // Use icon from server response if available, otherwise fallback to default
        const icon = file.icon || (isDirectory ? 'fas fa-folder folder-icon' : 'fas fa-file file-icon');
        
        // Properly escape filename for HTML attributes and display
        const escapedName = this.escapeHtml(file.name);
        const escapedPath = this.escapeHtml(file.path || file.name);
        
        // Check if item is cut (for visual feedback)
        const isCut = this.cutItems.some(cutItem => cutItem.name === file.name && cutItem.path === this.currentPath);
        if (isCut) {
            item.classList.add('cut-item');
        }
        
        // Check if item is selected
        const isSelected = this.selectedItems.has(file.name);
        if (isSelected) {
            item.classList.add('selected-item');
        }
        
        item.innerHTML = `
            <div class="file-name">
                <input type="checkbox" class="file-checkbox" ${file.isParent ? 'style="display:none"' : ''} ${isSelected ? 'checked' : ''}>
                <i class="${icon}"></i>
                <span title="${escapedName}">${escapedName}</span>
            </div>
            <div class="file-size">${file.size || ''}</div>
            <div class="file-date">${file.modified || ''}</div>
            <div class="file-actions">
                ${!file.isParent ? `
                    <button class="action-btn move" onclick="fileManager.cutItem('${escapedPath}')" title="Вырезать (Ctrl+X)">
                        <i class="fas fa-cut"></i>
                    </button>
                    <button class="action-btn download" onclick="fileManager.downloadItem('${escapedPath}', ${isDirectory})" title="Скачать">
                        <i class="fas fa-download"></i>
                    </button>
                    <button class="action-btn delete" onclick="fileManager.showDeleteModal('${escapedPath}', ${isDirectory})" title="Удалить">
                        <i class="fas fa-trash"></i>
                    </button>
                ` : ''}
            </div>
        `;

        // Add checkbox event listener
        const checkbox = item.querySelector('.file-checkbox');
        if (checkbox) {
            checkbox.addEventListener('change', (e) => {
                e.stopPropagation();
                this.toggleItemSelection(file.name, e.target.checked);
            });
        }

        // Add click handler for navigation
        if (isDirectory) {
            item.style.cursor = 'pointer';
            item.addEventListener('click', (e) => {
                if (!e.target.closest('.file-actions') && !e.target.closest('.file-checkbox')) {
                    this.navigateToDirectory(file.name, file.isParent);
                }
            });
        }

        // Add right-click context menu
        item.addEventListener('contextmenu', (e) => {
            e.preventDefault();
            if (!file.isParent) {
                this.showContextMenu(e, file);
            }
        });

        return item;
    }

    // Helper function to escape HTML entities
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // File selection methods
    toggleItemSelection(fileName, selected) {
        const fileItem = document.querySelector(`[data-file-name="${fileName}"]`);
        
        if (selected) {
            this.selectedItems.add(fileName);
            if (fileItem) {
                fileItem.classList.add('selected-item');
            }
        } else {
            this.selectedItems.delete(fileName);
            if (fileItem) {
                fileItem.classList.remove('selected-item');
            }
        }
        this.updateSelectionUI();
    }

    updateSelectionUI() {
        const selectedCount = this.selectedItems.size;
        const selectionInfo = document.getElementById('selectionInfo');
        
        if (selectedCount > 0) {
            if (!selectionInfo) {
                const info = document.createElement('div');
                info.id = 'selectionInfo';
                info.className = 'selection-info';
                info.innerHTML = `
                    <span>Выбрано: <strong>${selectedCount}</strong> элемент(ов)</span>
                    <button onclick="fileManager.cutSelectedItems()" title="Вырезать выбранные (Ctrl+X)">
                        <i class="fas fa-cut"></i> Вырезать
                    </button>
                    <button onclick="fileManager.clearSelection()" title="Снять выделение">
                        <i class="fas fa-times"></i> Отменить
                    </button>
                `;
                document.querySelector('.header').appendChild(info);
            } else {
                selectionInfo.querySelector('strong').textContent = selectedCount;
            }
        } else if (selectionInfo) {
            selectionInfo.remove();
        }
    }

    clearSelection() {
        this.selectedItems.clear();
        document.querySelectorAll('.file-checkbox').forEach(cb => cb.checked = false);
        document.querySelectorAll('.selected-item').forEach(item => {
            item.classList.remove('selected-item');
        });
        this.updateSelectionUI();
    }

    // Cut and paste methods
    cutItem(fileName) {
        const fullPath = this.currentPath ? `${this.currentPath}/${fileName}` : fileName;
        this.cutItems = [{
            name: fileName,
            path: this.currentPath,
            fullPath: fullPath
        }];
        this.showNotification(`Элемент "${fileName}" вырезан. Нажмите Ctrl+V для вставки.`, 'info');
        this.updatePasteButton();
        this.loadFiles(); // Refresh to show visual feedback
    }

    cutSelectedItems() {
        if (this.selectedItems.size === 0) {
            this.showNotification('Выберите элементы для вырезания', 'warning');
            return;
        }

        this.cutItems = Array.from(this.selectedItems).map(fileName => ({
            name: fileName,
            path: this.currentPath,
            fullPath: this.currentPath ? `${this.currentPath}/${fileName}` : fileName
        }));

        this.showNotification(`Вырезано ${this.cutItems.length} элемент(ов). Нажмите Ctrl+V для вставки.`, 'info');
        this.clearSelection();
        this.updatePasteButton();
        this.loadFiles(); // Refresh to show visual feedback
    }

    updatePasteButton() {
        const pasteBtn = document.getElementById('pasteBtn');
        if (pasteBtn) {
            if (this.cutItems.length > 0) {
                pasteBtn.classList.add('active');
                pasteBtn.title = `Вставить ${this.cutItems.length} элемент(ов) (Ctrl+V)`;
            } else {
                pasteBtn.classList.remove('active');
                pasteBtn.title = 'Вставить (Ctrl+V)';
            }
        }
    }

    async pasteItems() {
        if (this.cutItems.length === 0) {
            this.showNotification('Нет элементов для вставки', 'warning');
            return;
        }

        let successCount = 0;
        let errorCount = 0;

        for (const item of this.cutItems) {
            try {
                const sourcePath = item.fullPath;
                const destinationPath = this.currentPath ? `${this.currentPath}/${item.name}` : item.name;

                // Skip if trying to paste in the same location
                if (item.path === this.currentPath) {
                    continue;
                }

                const response = await fetch('php/move.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        source: sourcePath,
                        destination: destinationPath
                    })
                });

                const data = await response.json();

                if (data.success) {
                    successCount++;
                } else {
                    console.error('Move error:', data.error);
                    errorCount++;
                }
            } catch (error) {
                console.error('Move request error:', error);
                errorCount++;
            }
        }

        // Clear cut items and refresh
        this.cutItems = [];
        this.updatePasteButton();
        this.loadFiles();

        // Show result notification
        if (successCount > 0 && errorCount === 0) {
            this.showNotification(`Успешно перемещено ${successCount} элемент(ов)`, 'success');
        } else if (successCount > 0 && errorCount > 0) {
            this.showNotification(`Перемещено ${successCount} элемент(ов), ошибок: ${errorCount}`, 'warning');
        } else {
            this.showNotification('Не удалось переместить элементы', 'error');
        }
    }

    // Context menu
    showContextMenu(event, file) {
        // Remove existing context menu
        const existingMenu = document.querySelector('.context-menu');
        if (existingMenu) {
            existingMenu.remove();
        }

        const menu = document.createElement('div');
        menu.className = 'context-menu';
        menu.style.left = event.pageX + 'px';
        menu.style.top = event.pageY + 'px';

        const isDirectory = file.type === 'directory';
        
        menu.innerHTML = `
            <div class="context-menu-item" onclick="fileManager.cutItem('${file.name}')">
                <i class="fas fa-cut"></i> Вырезать
            </div>
            <div class="context-menu-item" onclick="fileManager.downloadItem('${file.name}', ${isDirectory})">
                <i class="fas fa-download"></i> Скачать
            </div>
            <div class="context-menu-item" onclick="fileManager.showDeleteModal('${file.name}', ${isDirectory})">
                <i class="fas fa-trash"></i> Удалить
            </div>
        `;

        document.body.appendChild(menu);

        // Remove menu on click outside
        setTimeout(() => {
            document.addEventListener('click', function removeMenu() {
                menu.remove();
                document.removeEventListener('click', removeMenu);
            });
        }, 100);
    }

    navigateToDirectory(dirName, isParent = false) {
        if (isParent) {
            // Go to parent directory
            const pathParts = this.currentPath.split('/').filter(part => part !== '');
            pathParts.pop();
            this.currentPath = pathParts.join('/');
        } else {
            // Go to subdirectory
            this.currentPath = this.currentPath ? `${this.currentPath}/${dirName}` : dirName;
        }
        this.loadFiles();
    }

    updateBreadcrumb() {
        const pathElement = document.getElementById('currentPath');
        pathElement.textContent = '/' + this.currentPath;
    }

    async testConnection() {
        try {
            const response = await fetch('php/check_config.php');
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            const data = await response.json();
            console.log('Server configuration:', data);
            return data;
        } catch (error) {
            console.error('Connection test failed:', error);
            this.showNotification('Ошибка соединения с сервером: ' + error.message, 'error');
            return null;
        }
    }

    async handleFileSelect(files) {
        if (files.length === 0) return;

        const formData = new FormData();
        
        for (let file of files) {
            formData.append('files[]', file);
        }
        
        formData.append('path', this.currentPath);

        this.showUploadProgress();
        
        try {
            const response = await fetch('php/upload.php', {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                throw new Error(`Сервер вернул неожиданный ответ: ${text.substring(0, 200)}`);
            }

            const data = await response.json();
            
            if (data.success) {
                this.showNotification(`Успешно загружено ${files.length} файл(ов)`, 'success');
                if (data.warnings && data.warnings.length > 0) {
                    this.showNotification('Предупреждения: ' + data.warnings.join(', '), 'warning');
                }
                this.loadFiles();
                this.hideUploadProgress();
                this.toggleUploadArea();
            } else {
                this.showNotification('Ошибка загрузки: ' + data.error, 'error');
                this.hideUploadProgress();
            }
        } catch (error) {
            console.error('Upload error:', error);
            this.showNotification('Ошибка соединения при загрузке: ' + error.message, 'error');
            this.hideUploadProgress();
        }

        // Reset file input
        document.getElementById('fileInput').value = '';
    }

    showUploadProgress() {
        document.getElementById('uploadProgress').classList.remove('hidden');
        // Simulate progress for demo (in real implementation, use XMLHttpRequest for progress tracking)
        let progress = 0;
        const interval = setInterval(() => {
            progress += 10;
            document.getElementById('progressFill').style.width = progress + '%';
            document.getElementById('progressText').textContent = progress + '%';
            
            if (progress >= 100) {
                clearInterval(interval);
            }
        }, 200);
    }

    hideUploadProgress() {
        document.getElementById('uploadProgress').classList.add('hidden');
        document.getElementById('progressFill').style.width = '0%';
        document.getElementById('progressText').textContent = '0%';
    }

    showCreateFolderModal() {
        document.getElementById('createFolderModal').classList.remove('hidden');
        document.getElementById('folderNameInput').focus();
    }

    hideCreateFolderModal() {
        document.getElementById('createFolderModal').classList.add('hidden');
        document.getElementById('folderNameInput').value = '';
    }

    async createFolder() {
        const folderName = document.getElementById('folderNameInput').value.trim();
        
        if (!folderName) {
            this.showNotification('Введите имя папки', 'warning');
            return;
        }

        try {
            console.log('Creating folder:', folderName, 'in path:', this.currentPath);
            
            const response = await fetch('php/create_folder.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    name: folderName,
                    path: this.currentPath
                })
            });

            console.log('Response status:', response.status);
            console.log('Response headers:', response.headers);

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const responseText = await response.text();
            console.log('Response text:', responseText);

            let data;
            try {
                data = JSON.parse(responseText);
            } catch (parseError) {
                console.error('JSON parse error:', parseError);
                throw new Error('Сервер вернул некорректный JSON: ' + responseText.substring(0, 100));
            }
            
            if (data.success) {
                this.showNotification('Папка создана успешно', 'success');
                this.loadFiles();
                this.hideCreateFolderModal();
            } else {
                this.showNotification('Ошибка создания папки: ' + data.error, 'error');
            }
        } catch (error) {
            console.error('Create folder error:', error);
            this.showNotification('Ошибка соединения: ' + error.message, 'error');
        }
    }

    showDeleteModal(itemName, isDirectory) {
        this.deleteTarget = { name: itemName, isDirectory };
        const message = isDirectory ? 
            `Вы уверены, что хотите удалить папку "${itemName}" и все её содержимое?` :
            `Вы уверены, что хотите удалить файл "${itemName}"?`;
        
        document.getElementById('deleteMessage').textContent = message;
        document.getElementById('deleteModal').classList.remove('hidden');
    }

    hideDeleteModal() {
        document.getElementById('deleteModal').classList.add('hidden');
        this.deleteTarget = null;
    }

    async confirmDelete() {
        if (!this.deleteTarget) return;

        try {
            const response = await fetch('php/delete.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    name: this.deleteTarget.name,
                    path: this.currentPath,
                    isDirectory: this.deleteTarget.isDirectory
                })
            });

            const data = await response.json();
            
            if (data.success) {
                this.showNotification('Элемент удален успешно', 'success');
                this.loadFiles();
                this.hideDeleteModal();
            } else {
                this.showNotification('Ошибка удаления: ' + data.error, 'error');
            }
        } catch (error) {
            this.showNotification('Ошибка соединения', 'error');
            console.error('Delete error:', error);
        }
    }

    async downloadItem(itemName, isDirectory) {
        const url = isDirectory ? 
            `php/download_directory.php?path=${encodeURIComponent(this.currentPath)}&name=${encodeURIComponent(itemName)}` :
            `php/download.php?path=${encodeURIComponent(this.currentPath)}&name=${encodeURIComponent(itemName)}`;
        
        try {
            // Show loading notification for directories (they take longer)
            if (isDirectory) {
                this.showNotification('Создание архива директории...', 'info');
            }
            
            // Test the URL first for directories to catch errors
            if (isDirectory) {
                const testResponse = await fetch(url, { method: 'HEAD' });
                if (!testResponse.ok) {
                    // Try to get error message
                    const errorResponse = await fetch(url);
                    const errorText = await errorResponse.text();
                    
                    // Extract error message from HTML if present
                    const errorMatch = errorText.match(/<p><strong>Причина:<\/strong>\s*([^<]+)<\/p>/);
                    const errorMessage = errorMatch ? errorMatch[1] : 'Неизвестная ошибка сервера';
                    
                    throw new Error(errorMessage);
                }
            }
            
            // Create temporary link and click it
            const link = document.createElement('a');
            link.href = url;
            link.download = itemName + (isDirectory ? '.zip' : '');
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            if (isDirectory) {
                this.showNotification('Скачивание архива начато', 'success');
            }
            
        } catch (error) {
            console.error('Download error:', error);
            this.showNotification('Ошибка скачивания: ' + error.message, 'error');
        }
    }

    showNotification(message, type = 'success') {
        const notification = document.createElement('div');
        notification.className = `notification ${type}`;
        notification.textContent = message;
        
        document.getElementById('notifications').appendChild(notification);
        
        // Auto remove after 5 seconds
        setTimeout(() => {
            notification.remove();
        }, 5000);
    }



    // Archive functionality
    showCreateArchiveModal() {
        this.loadArchiveItems();
        document.getElementById('createArchiveModal').classList.remove('hidden');
        document.getElementById('archiveNameInput').focus();
    }

    hideCreateArchiveModal() {
        document.getElementById('createArchiveModal').classList.add('hidden');
        document.getElementById('archiveNameInput').value = '';
        // Clear checkboxes
        const checkboxes = document.querySelectorAll('#archiveItemsList input[type="checkbox"]');
        checkboxes.forEach(cb => cb.checked = false);
    }

    async loadArchiveItems() {
        try {
            const response = await fetch(`php/list_files.php?path=${encodeURIComponent(this.currentPath)}`);
            const data = await response.json();
            
            if (data.success) {
                this.renderArchiveItems(data.files);
            } else {
                this.showNotification('Ошибка загрузки файлов для архивирования: ' + data.error, 'error');
            }
        } catch (error) {
            this.showNotification('Ошибка соединения с сервером', 'error');
            console.error('Error loading archive items:', error);
        }
    }

    renderArchiveItems(files) {
        const container = document.getElementById('archiveItemsList');
        container.innerHTML = '';

        if (files.length === 0) {
            container.innerHTML = '<p style="text-align: center; color: #718096;">Нет файлов для архивирования</p>';
            return;
        }

        files.forEach(file => {
            const item = document.createElement('div');
            item.className = 'archive-item';
            
            const isDirectory = file.type === 'directory';
            const icon = isDirectory ? 'fas fa-folder folder-icon' : 'fas fa-file file-icon';
            
            item.innerHTML = `
                <input type="checkbox" id="archive_${file.name}" value="${file.name}" data-type="${file.type}">
                <label for="archive_${file.name}">
                    <i class="${icon}"></i>
                    <span>${file.name}</span>
                </label>
            `;
            
            container.appendChild(item);
        });
    }

    async createArchive() {
        const archiveName = document.getElementById('archiveNameInput').value.trim();
        const archiveType = document.querySelector('input[name="archiveType"]:checked').value;
        
        if (!archiveName) {
            this.showNotification('Введите имя архива', 'warning');
            return;
        }

        // Get selected items
        const selectedItems = [];
        const checkboxes = document.querySelectorAll('#archiveItemsList input[type="checkbox"]:checked');
        
        if (checkboxes.length === 0) {
            this.showNotification('Выберите элементы для архивирования', 'warning');
            return;
        }

        checkboxes.forEach(cb => {
            selectedItems.push({
                name: cb.value,
                type: cb.dataset.type
            });
        });

        try {
            const response = await fetch('php/create_archive.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    archiveName: archiveName,
                    archiveType: archiveType,
                    items: selectedItems,
                    path: this.currentPath
                })
            });

            const data = await response.json();
            
            if (data.success) {
                this.showNotification('Архив создан успешно', 'success');
                this.loadFiles();
                this.hideCreateArchiveModal();
            } else {
                this.showNotification('Ошибка создания архива: ' + data.error, 'error');
            }
        } catch (error) {
            this.showNotification('Ошибка соединения', 'error');
            console.error('Create archive error:', error);
        }
    }

    
}

// Initialize file manager when page loads
let fileManager;
document.addEventListener('DOMContentLoaded', () => {
    fileManager = new FileManager();
});