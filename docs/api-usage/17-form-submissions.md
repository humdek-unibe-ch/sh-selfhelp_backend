# Form Submission APIs

## Overview

The Form Submission APIs handle user-generated content through web forms. These endpoints support both JSON and multipart/form-data submissions, enabling rich form experiences with file uploads.

## Core Concepts

### Form Submissions
- **Data Collection**: User input from web forms
- **File Uploads**: Support for file attachments
- **Section Integration**: Forms tied to specific page sections
- **Data Storage**: Form data stored in configurable data tables

### Content Types
- **JSON Forms**: Standard form data submission
- **Multipart Forms**: Forms with file uploads
- **Update Operations**: Modify existing form submissions
- **Delete Operations**: Remove form data

## Form Operations

### Submit Form

Submit new form data from a web form.

**Endpoint:** `POST /cms-api/v1/forms/submit`

**Authentication:** None (public endpoint)

**Request Body (JSON):**
```json
{
  "page_id": 1,
  "section_id": 5,
  "form_data": {
    "name": "John Doe",
    "email": "john@example.com",
    "message": "Contact form submission"
  }
}
```

*Note: Form submission schemas are validated dynamically based on the specific form configuration.*

**Request Body (Multipart with files):**
```
Content-Type: multipart/form-data

page_id=1
section_id=5
form_data={"name":"John","email":"john@example.com"}
files[]=@document.pdf
```

**Response:**
```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": false,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": {
    "submission_id": 123,
    "status": "submitted",
    "message": "Form submitted successfully"
  }
}
```

### Update Form Submission

Modify an existing form submission.

**Endpoint:** `PUT /cms-api/v1/forms/update`

**Authentication:** None (public endpoint)

**Request Body:**
```json
{
  "page_id": 1,
  "section_id": 5,
  "form_data": {
    "name": "John Smith",
    "email": "john.smith@example.com"
  },
  "update_based_on": {
    "email": "john@example.com"
  }
}
```

**Response:** Update confirmation

### Delete Form Submission

Remove a form submission from the system.

**Endpoint:** `DELETE /cms-api/v1/forms/delete`

**Authentication:** None (public endpoint)

**Request Body:**
```json
{
  "record_id": 123,
  "page_id": 1,
  "section_id": 5
}
```

**Response:** Deletion confirmation

## Form Data Structure

### Form Data Object

```json
{
  "page_id": 1,
  "section_id": 5,
  "form_data": {
    "field_name": "field_value",
    "email": "user@example.com",
    "checkbox_field": true,
    "select_field": "option_1",
    "multi_select": ["option_1", "option_2"],
    "file_field": "uploaded_file_id"
  },
  "files": ["file1.jpg", "document.pdf"]
}
```

## Frontend Integration Examples

### Form Submission Handler

```javascript
const submitForm = async (formData, files = []) => {
  try {
    const data = new FormData();

    // Add form metadata
    data.append('page_id', formData.pageId);
    data.append('section_id', formData.sectionId);

    // Add form data as JSON string
    data.append('form_data', JSON.stringify(formData.fields));

    // Add files
    files.forEach(file => {
      data.append('files[]', file);
    });

    const response = await fetch('/cms-api/v1/forms/submit', {
      method: 'POST',
      body: data
    });

    const result = await response.json();

    if (response.ok) {
      showSuccess('Form submitted successfully!');
      // Reset form
      resetForm();
    } else {
      showError(result.error || 'Form submission failed');
    }
  } catch (error) {
    showError('Network error. Please try again.');
  }
};

// Usage
const handleSubmit = (e) => {
  e.preventDefault();

  const formData = {
    pageId: 1,
    sectionId: 5,
    fields: {
      name: document.getElementById('name').value,
      email: document.getElementById('email').value,
      message: document.getElementById('message').value
    }
  };

  const files = document.getElementById('files').files;
  submitForm(formData, Array.from(files));
};
```

### File Upload Progress

```javascript
const uploadWithProgress = (formData, files, onProgress) => {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();

    xhr.upload.addEventListener('progress', (e) => {
      if (e.lengthComputable) {
        const percentComplete = (e.loaded / e.total) * 100;
        onProgress(percentComplete);
      }
    });

    xhr.addEventListener('load', () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        resolve(JSON.parse(xhr.responseText));
      } else {
        reject(new Error(xhr.responseText));
      }
    });

    xhr.addEventListener('error', () => {
      reject(new Error('Upload failed'));
    });

    const data = new FormData();
    data.append('page_id', formData.pageId);
    data.append('section_id', formData.sectionId);
    data.append('form_data', JSON.stringify(formData.fields));

    files.forEach(file => {
      data.append('files[]', file);
    });

    xhr.open('POST', '/cms-api/v1/forms/submit');
    xhr.send(data);
  });
};

// Usage with progress bar
const handleSubmitWithProgress = async (e) => {
  e.preventDefault();

  const progressBar = document.getElementById('upload-progress');
  const submitButton = document.getElementById('submit-btn');

  submitButton.disabled = true;
  progressBar.style.display = 'block';

  try {
    const result = await uploadWithProgress(formData, files, (progress) => {
      progressBar.value = progress;
    });

    showSuccess('Form submitted successfully!');
    resetForm();
  } catch (error) {
    showError('Upload failed. Please try again.');
  } finally {
    submitButton.disabled = false;
    progressBar.style.display = 'none';
  }
};
```

### Form Validation

```javascript
const validateForm = (formData) => {
  const errors = {};

  // Required fields
  if (!formData.fields.name?.trim()) {
    errors.name = 'Name is required';
  }

  // Email validation
  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  if (!formData.fields.email?.trim()) {
    errors.email = 'Email is required';
  } else if (!emailRegex.test(formData.fields.email)) {
    errors.email = 'Please enter a valid email address';
  }

  // File validation
  if (files.length > 0) {
    files.forEach((file, index) => {
      if (file.size > 10 * 1024 * 1024) { // 10MB limit
        errors[`file_${index}`] = 'File size must be less than 10MB';
      }

      const allowedTypes = ['image/jpeg', 'image/png', 'application/pdf'];
      if (!allowedTypes.includes(file.type)) {
        errors[`file_${index}`] = 'File type not allowed';
      }
    });
  }

  return errors;
};

// Enhanced form submission with validation
const submitValidatedForm = async (formData, files) => {
  const validationErrors = validateForm(formData, files);

  if (Object.keys(validationErrors).length > 0) {
    showValidationErrors(validationErrors);
    return;
  }

  // Proceed with submission
  await submitForm(formData, files);
};
```

## Security Considerations

1. **Input Validation**: All form data is validated against schemas
2. **File Upload Security**: File type and size restrictions
3. **Rate Limiting**: Prevent form spam and abuse
4. **Data Sanitization**: Input sanitization to prevent XSS
5. **CSRF Protection**: Cross-site request forgery protection
6. **Captcha Integration**: Optional CAPTCHA for spam prevention

## Best Practices

1. **Client-Side Validation**: Validate forms before submission
2. **Progress Indicators**: Show upload progress for large files
3. **Error Handling**: Comprehensive error messages and recovery
4. **File Management**: Handle multiple file uploads gracefully
5. **Form State**: Preserve form state during submission failures
6. **Accessibility**: Ensure forms are accessible to all users
7. **Mobile Optimization**: Responsive form design

---

**Back to:** [API Overview](../README.md)
