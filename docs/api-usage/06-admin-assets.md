# Admin Assets APIs

## Overview

The Admin Assets APIs provide comprehensive file management functionality for the SelfHelp CMS. Assets include images, documents, videos, and other media files that can be uploaded, organized, and managed through the admin interface.

## Core Concepts

- **Assets**: Files uploaded to the system (images, documents, videos, etc.)
- **Folders**: Organizational structure for assets
- **File Types**: Supported MIME types with validation
- **File Naming**: Automatic naming or custom naming options
- **Overwriting**: Control over whether to replace existing files

## Get All Assets

Retrieve a paginated list of all assets with optional filtering.

**Endpoint:** `GET /cms-api/v1/admin/assets`

**Authentication:** Required (JWT Bearer token)

**Query Parameters:**
- `page`: Page number (default: 1)
- `pageSize`: Items per page (default: 100, max: 1000)
- `search`: Search term for filename filtering
- `folder`: Filter by folder path

**Response:**
```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": {
    "assets": [
      {
        "id": 1,
        "file_name": "logo.png",
        "original_name": "company-logo.png",
        "mime_type": "image/png",
        "file_size": 245760,
        "folder": "images",
        "file_path": "/uploads/assets/images/logo.png",
        "url": "/assets/images/logo.png",
        "created_at": "2024-01-15T10:30:00Z",
        "updated_at": "2024-01-15T10:30:00Z"
      },
      {
        "id": 2,
        "file_name": "document.pdf",
        "original_name": "user-manual.pdf",
        "mime_type": "application/pdf",
        "file_size": 2097152,
        "folder": "documents",
        "file_path": "/uploads/assets/documents/document.pdf",
        "url": "/assets/documents/document.pdf",
        "created_at": "2024-01-16T14:20:00Z",
        "updated_at": "2024-01-16T14:20:00Z"
      }
    ],
    "total": 150,
    "page": 1,
    "pageSize": 100,
    "totalPages": 2
  }
}
```

**Permissions:** `admin.asset.read`

## Get Single Asset

Retrieve detailed information about a specific asset.

**Endpoint:** `GET /cms-api/v1/admin/assets/{assetId}`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `assetId`: Asset ID

**Response:**
```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": {
    "asset": {
      "id": 1,
      "file_name": "logo.png",
      "original_name": "company-logo.png",
      "mime_type": "image/png",
      "file_size": 245760,
      "folder": "images",
      "file_path": "/uploads/assets/images/logo.png",
      "url": "/assets/images/logo.png",
      "dimensions": {
        "width": 800,
        "height": 600
      },
      "created_at": "2024-01-15T10:30:00Z",
      "updated_at": "2024-01-15T10:30:00Z"
    }
  }
}
```

**Permissions:** `admin.asset.read`

## Upload Single Asset

Upload a single file to the asset library.

**Endpoint:** `POST /cms-api/v1/admin/assets`

**Authentication:** Required (JWT Bearer token)

**Content-Type:** `multipart/form-data`

**Form Data:**
- `file`: The file to upload (required)
- `folder`: Target folder path (optional)
- `file_name`: Custom filename (optional)
- `overwrite`: Whether to overwrite existing file (default: false)

[View JSON Schema](../../config/schemas/api/v1/requests/admin/create_assets.json)

**Response:**
```json
{
  "status": 201,
  "message": "Created",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": {
    "asset": {
      "id": 3,
      "file_name": "banner.jpg",
      "original_name": "website-banner.jpg",
      "mime_type": "image/jpeg",
      "file_size": 512000,
      "folder": "images/banners",
      "file_path": "/uploads/assets/images/banners/banner.jpg",
      "url": "/assets/images/banners/banner.jpg",
      "dimensions": {
        "width": 1200,
        "height": 400
      },
      "created_at": "2025-01-23T10:30:00Z",
      "updated_at": "2025-01-23T10:30:00Z"
    }
  }
}
```

**Permissions:** `admin.asset.create`

## Upload Multiple Assets

Upload multiple files simultaneously.

**Endpoint:** `POST /cms-api/v1/admin/assets`

**Authentication:** Required (JWT Bearer token)

**Content-Type:** `multipart/form-data`

**Form Data:**
- `files`: Array of files to upload (required)
- `folder`: Target folder path (optional)
- `file_names`: Array of custom filenames (optional)
- `overwrite`: Whether to overwrite existing files (default: false)

**Response:**
```json
{
  "status": 201,
  "message": "Created",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": {
    "uploaded": [
      {
        "id": 4,
        "file_name": "image1.jpg",
        "original_name": "photo1.jpg",
        "mime_type": "image/jpeg",
        "file_size": 256000,
        "folder": "gallery",
        "url": "/assets/gallery/image1.jpg"
      },
      {
        "id": 5,
        "file_name": "image2.png",
        "original_name": "photo2.png",
        "mime_type": "image/png",
        "file_size": 128000,
        "folder": "gallery",
        "url": "/assets/gallery/image2.png"
      }
    ],
    "failed_uploads": 0,
    "total_uploaded": 2,
    "errors": []
  }
}
```

**Response (with failures):**
```json
{
  "status": 206,
  "message": "Partial Content",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": {
    "uploaded": [
      {
        "id": 6,
        "file_name": "valid-file.jpg",
        "original_name": "good-photo.jpg",
        "mime_type": "image/jpeg",
        "file_size": 256000,
        "folder": "uploads",
        "url": "/assets/uploads/valid-file.jpg"
      }
    ],
    "failed_uploads": 1,
    "total_uploaded": 1,
    "errors": [
      {
        "file": "corrupted-file.jpg",
        "error": "File is corrupted or invalid"
      }
    ]
  }
}
```

**Permissions:** `admin.asset.create`

## Delete Asset

Remove an asset from the system.

**Endpoint:** `DELETE /cms-api/v1/admin/assets/{assetId}`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `assetId`: ID of the asset to delete

**Response:**
```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": {
    "deleted": true
  }
}
```

**Permissions:** `admin.asset.delete`

## File Upload Specifications

### Supported File Types

The system supports various file types with appropriate validation:

**Images:**
- JPEG/JPG (`image/jpeg`)
- PNG (`image/png`)
- GIF (`image/gif`)
- WebP (`image/webp`)
- SVG (`image/svg+xml`)

**Documents:**
- PDF (`application/pdf`)
- Microsoft Office (DOC, DOCX, XLS, XLSX, PPT, PPTX)
- OpenDocument (ODT, ODS, ODP)
- Text files (`text/plain`, `text/csv`)

**Videos:**
- MP4 (`video/mp4`)
- WebM (`video/webm`)
- AVI (`video/avi`)

**Audio:**
- MP3 (`audio/mpeg`)
- WAV (`audio/wav`)
- OGG (`audio/ogg`)

### File Size Limits

- **Images**: 10MB per file
- **Documents**: 25MB per file
- **Videos**: 100MB per file
- **Audio**: 50MB per file
- **Total upload**: 500MB per request

### Folder Structure

Assets can be organized in folders:

```
/assets/
├── images/
│   ├── logos/
│   ├── banners/
│   └── gallery/
├── documents/
│   ├── manuals/
│   └── templates/
├── videos/
└── audio/
```

## Frontend Integration Examples

### Asset Manager Component

```javascript
const AssetManager = () => {
  const [assets, setAssets] = useState([]);
  const [uploading, setUploading] = useState(false);
  const [selectedFolder, setSelectedFolder] = useState('');

  useEffect(() => {
    loadAssets();
  }, [selectedFolder]);

  const loadAssets = async (page = 1, search = '') => {
    try {
      const params = new URLSearchParams({
        page,
        pageSize: 50,
        search,
        folder: selectedFolder
      });

      const response = await apiRequest(`/admin/assets?${params}`);
      setAssets(response.data.assets);
    } catch (error) {
      console.error('Failed to load assets:', error);
    }
  };

  const uploadFiles = async (files, folder = '') => {
    setUploading(true);

    try {
      const formData = new FormData();

      if (files.length === 1) {
        // Single file upload
        formData.append('file', files[0]);
      } else {
        // Multiple file upload
        files.forEach(file => {
          formData.append('files[]', file);
        });
      }

      if (folder) {
        formData.append('folder', folder);
      }

      formData.append('overwrite', 'false');

      const response = await apiRequest('/admin/assets', {
        method: 'POST',
        body: formData,
        headers: {
          // Don't set Content-Type, let browser set it with boundary
        }
      });

      if (response.data.uploaded) {
        // Refresh asset list
        loadAssets();
        showSuccess(`Uploaded ${response.data.total_uploaded} file(s)`);
      }

      if (response.data.failed_uploads > 0) {
        showWarning(`${response.data.failed_uploads} file(s) failed to upload`);
      }
    } catch (error) {
      showError('Upload failed');
    } finally {
      setUploading(false);
    }
  };

  const deleteAsset = async (assetId, fileName) => {
    if (!confirm(`Delete "${fileName}"?`)) return;

    try {
      await apiRequest(`/admin/assets/${assetId}`, {
        method: 'DELETE'
      });

      setAssets(assets.filter(asset => asset.id !== assetId));
      showSuccess('Asset deleted');
    } catch (error) {
      showError('Failed to delete asset');
    }
  };

  return (
    <div className="asset-manager">
      <div className="asset-controls">
        <div className="folder-selector">
          <select
            value={selectedFolder}
            onChange={(e) => setSelectedFolder(e.target.value)}
          >
            <option value="">All Folders</option>
            <option value="images">Images</option>
            <option value="documents">Documents</option>
            <option value="videos">Videos</option>
          </select>
        </div>

        <FileUpload
          onUpload={uploadFiles}
          multiple={true}
          disabled={uploading}
        />
      </div>

      {uploading && <div className="upload-progress">Uploading...</div>}

      <div className="assets-grid">
        {assets.map(asset => (
          <AssetItem
            key={asset.id}
            asset={asset}
            onDelete={() => deleteAsset(asset.id, asset.file_name)}
          />
        ))}
      </div>
    </div>
  );
};
```

### File Upload Component

```javascript
const FileUpload = ({ onUpload, multiple = false, disabled = false }) => {
  const fileInputRef = useRef(null);

  const handleFileSelect = (event) => {
    const files = Array.from(event.target.files);

    if (files.length > 0) {
      // Validate file types and sizes
      const validFiles = files.filter(file => {
        // Check file size (10MB limit)
        if (file.size > 10 * 1024 * 1024) {
          showError(`${file.name} is too large (max 10MB)`);
          return false;
        }

        // Check file type
        const allowedTypes = [
          'image/jpeg', 'image/png', 'image/gif', 'image/webp',
          'application/pdf', 'video/mp4', 'audio/mpeg'
        ];

        if (!allowedTypes.includes(file.type)) {
          showError(`${file.name} has unsupported file type`);
          return false;
        }

        return true;
      });

      if (validFiles.length > 0) {
        onUpload(validFiles);
      }
    }

    // Clear input
    event.target.value = '';
  };

  const handleDrop = (event) => {
    event.preventDefault();

    const files = Array.from(event.dataTransfer.files);
    if (files.length > 0) {
      onUpload(files);
    }
  };

  const handleDragOver = (event) => {
    event.preventDefault();
  };

  return (
    <div
      className={`file-upload ${disabled ? 'disabled' : ''}`}
      onDrop={handleDrop}
      onDragOver={handleDragOver}
    >
      <input
        ref={fileInputRef}
        type="file"
        multiple={multiple}
        onChange={handleFileSelect}
        accept="image/*,application/pdf,video/*,audio/*"
        style={{ display: 'none' }}
      />

      <button
        type="button"
        onClick={() => fileInputRef.current?.click()}
        disabled={disabled}
      >
        {multiple ? 'Upload Files' : 'Upload File'}
      </button>

      <div className="upload-info">
        <small>Drop files here or click to browse</small>
        <small>Supported: Images, PDFs, Videos, Audio (max 10MB each)</small>
      </div>
    </div>
  );
};
```

### Asset Preview Component

```javascript
const AssetItem = ({ asset, onDelete }) => {
  const [previewUrl, setPreviewUrl] = useState(null);

  useEffect(() => {
    // Generate preview for images
    if (asset.mime_type.startsWith('image/')) {
      setPreviewUrl(asset.url);
    }
  }, [asset]);

  const getFileIcon = (mimeType) => {
    if (mimeType.startsWith('image/')) return '🖼️';
    if (mimeType === 'application/pdf') return '📄';
    if (mimeType.startsWith('video/')) return '🎥';
    if (mimeType.startsWith('audio/')) return '🎵';
    return '📁';
  };

  const formatFileSize = (bytes) => {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
  };

  return (
    <div className="asset-item">
      <div className="asset-preview">
        {previewUrl ? (
          <img src={previewUrl} alt={asset.file_name} />
        ) : (
          <div className="file-icon">
            {getFileIcon(asset.mime_type)}
          </div>
        )}
      </div>

      <div className="asset-info">
        <div className="asset-name" title={asset.original_name}>
          {asset.file_name}
        </div>
        <div className="asset-meta">
          <span className="file-size">{formatFileSize(asset.file_size)}</span>
          <span className="file-type">{asset.mime_type}</span>
        </div>
        {asset.folder && (
          <div className="asset-folder">📁 {asset.folder}</div>
        )}
      </div>

      <div className="asset-actions">
        <button
          onClick={() => window.open(asset.url, '_blank')}
          title="View/Download"
        >
          👁️
        </button>
        <button
          onClick={() => navigator.clipboard.writeText(asset.url)}
          title="Copy URL"
        >
          📋
        </button>
        <button
          onClick={onDelete}
          title="Delete"
          className="delete-btn"
        >
          🗑️
        </button>
      </div>
    </div>
  );
};
```

## Error Handling

```javascript
const handleAssetOperation = async (operation, ...args) => {
  try {
    const response = await apiRequest(operation, ...args);

    if (response.status === 200 || response.status === 201) {
      return { success: true, data: response.data };
    } else if (response.status === 206) {
      // Partial success for multi-file uploads
      return {
        success: 'partial',
        data: response.data,
        message: `${response.data.total_uploaded} files uploaded, ${response.data.failed_uploads} failed`
      };
    }
  } catch (error) {
    const errorData = error.response?.data;

    if (errorData?.status === 400) {
      return {
        success: false,
        error: 'Validation failed',
        details: errorData.error,
        validation: errorData.validation
      };
    } else if (errorData?.status === 413) {
      return {
        success: false,
        error: 'File too large',
        action: 'reduce_file_size'
      };
    } else if (errorData?.status === 415) {
      return {
        success: false,
        error: 'Unsupported file type',
        action: 'change_file_type'
      };
    } else if (errorData?.status === 409) {
      return {
        success: false,
        error: 'File already exists',
        action: 'choose_different_name'
      };
    } else {
      return {
        success: false,
        error: 'Upload failed'
      };
    }
  }
};
```

## Best Practices

1. **File Validation**: Always validate file types and sizes before upload
2. **Progress Feedback**: Show upload progress for large files
3. **Error Recovery**: Provide clear error messages and recovery options
4. **Batch Operations**: Use multi-file upload for efficiency
5. **Caching**: Cache asset URLs and metadata for performance
6. **Access Control**: Respect file permissions and user access levels
7. **Storage Optimization**: Consider file compression and optimization
8. **Backup**: Regular backup of uploaded assets

## Integration with Content Management

Assets are integrated throughout the CMS:

- **Page Content**: Images and documents embedded in page sections
- **Media Libraries**: Organized asset collections
- **File References**: Assets linked to content sections
- **Responsive Images**: Automatic image resizing and optimization
- **CDN Integration**: Assets served via CDN for performance

---

**Next:** [Admin Users](./07-admin-users.md) | **Previous:** [Admin Languages](./05-admin-languages.md) | **Back to:** [API Overview](../README.md)
