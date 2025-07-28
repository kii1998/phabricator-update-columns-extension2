# Phorge Update Columns Extension

🚀 A modern AJAX-powered extension for Phorge workboards that enables intelligent bulk task movement based on priority levels.

![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)
![Phorge](https://img.shields.io/badge/Phorge-compatible-green.svg)
![Version](https://img.shields.io/badge/version-1.0.0-blue.svg)

## ✨ Features

- **🎯 One-Click Bulk Operations**: Replace manual drag-and-drop with intelligent bulk task movement
- **🤖 Smart Priority Mapping**: Automatically match tasks to appropriate columns based on priority levels
- **💫 Seamless AJAX Experience**: No page redirects, instant feedback with smooth modal dialogs
- **📊 Detailed Statistics**: View moved task counts and error information
- **⏱️ Auto-Refresh**: 3-second countdown with automatic page refresh after successful operations
- **🛡️ CSP Compliant**: Full Content Security Policy compliance for enhanced security
- **🔧 Cold Start Fix**: Intelligent task detection that works even on first workboard load
- **🔌 Phabricator Compatible**: Fully compatible with both Phorge and Phabricator environments

## 🎬 Demo

### Confirmation Dialog
```
┌─────────────────────────────────────┐
│ Update Task Columns                 │
├─────────────────────────────────────┤
│ This will move all tasks to         │
│ appropriate columns based on their  │
│ priority levels:                    │
│                                     │
│ • Unbreak Now! (100) → "Unbreak    │
│   Now!" column                      │
│ • High Priority (90, 80) → "High   │
│   Priority" column                  │
│ • Normal Priority (50) → "In       │
│   Progress" column                  │
│ • Low Priority (25, 1-39) → "Low   │
│   Priority" column                  │
│ • Wishlist (0) → "Wishlist" column │
│                                     │
│ Are you sure you want to continue?  │
├─────────────────────────────────────┤
│        [Update Columns] [Cancel]    │
└─────────────────────────────────────┘
```

### Success Dialog
```
┌─────────────────────────────────────┐
│ Update Columns Complete             │
├─────────────────────────────────────┤
│ ✅ Success!                        │
│                                     │
│ Successfully updated tasks based    │
│ on priority.                        │
│                                     │
│ • Tasks moved: 12                   │
│ • Total tasks: 15                   │
│ • Errors: 0                         │
│                                     │
│ Page will refresh in 3 seconds...   │
└─────────────────────────────────────┘
```

## 🚀 Quick Start

### Prerequisites

- Phorge or Phabricator installation (tested with latest versions)
- PHP 7.4 or higher
- Web server (Apache/Nginx) with proper permissions
- Write access to Phorge/Phabricator directories

### ⚡ Recent Critical Fixes (2025-01-26)

**Problem Solved**: "Cold Start" task detection issue where clicking "Update_Columns" button would fail to detect tasks on first try, requiring manual task movement to "activate" the workboard.

**Solution Implemented**: 
- ✅ Replaced position-based task detection with direct project-edge queries
- ✅ Fixed "Cannot read properties of null" JavaScript errors  
- ✅ Enhanced Phabricator API compatibility
- ✅ Added automated deployment scripts with password authentication

### Installation

1. **🚀 Quick Deployment (Recommended)**
   ```bash
   # Configure your server details first
   export PHABRICATOR_SERVER="YOUR_SERVER_IP"
   export PHABRICATOR_USER="YOUR_USERNAME"
   
   # Use quick deployment script
   ./quick_deploy.sh
   ```

2. **📖 Detailed Automated Deployment**
   - Refer to [`deploy_for_phabricator.md`](deploy_for_phabricator.md) for complete deployment guide

3. **🔧 Manual Deployment (if customization needed)**
   - See detailed steps below or in `docs/UPDATE_COLUMNS.md`

## 🚀 Automated Deployment (Recommended)

This project supports fully automated one-click deployment, suitable for local-to-server and server-to-Docker container complete workflows.

**We recommend first referring to the [`deploy_new.md`](deploy_new.md) documentation and following the steps to complete frontend and backend file automatic upload, container deployment, static resource rebuilding, and cache cleanup.**

## 📖 Documentation

- **[Phabricator Deployment Guide](deploy_for_phabricator.md)** - Complete deployment guide with latest fixes
- **[Quick Deploy Script](quick_deploy.sh)** - One-click deployment automation
- **[Complete Setup Guide](docs/UPDATE_COLUMNS.md)** - Detailed installation and configuration
- **[Button Integration Code](examples/button-integration-code.php)** - Ready-to-use button code
- **[Configuration Examples](examples/)** - Sample configuration files

### 🛠️ Deployment Tools

- **`ssh_server/ssh_password.exp`** - Automated SSH connection script (template)
- **`ssh_server/scp_password.exp`** - Automated file upload script (template)
- **`ssh_server/server_password`** - Server password file (create your own)
- **`quick_deploy.sh`** - Complete deployment automation

**Note**: The automation scripts are templates. You'll need to configure them with your specific server details and credentials.

## 🏗️ Architecture

### Backend Components

- **`UpdateColumnsController`** - Handles routing, permissions, and AJAX requests
- **`UpdateColumnsService`** - Core business logic and task movement processing  
- **`UpdateColumnsApplication`** - Route registration and application configuration

### Frontend Components

- **`UpdateColumnsAjax.js`** - Event handling, modal dialogs, and AJAX communication

### Priority Mapping

| Priority | Name | Target Columns | Description |
|----------|------|----------------|-------------|
| 100 | Unbreak Now! | `unbreak`, `urgent`, `emergency` | Critical issues |
| 90, 80 | High Priority | `high`, `important`, `critical` | High priority tasks |
| 50 | Normal Priority | `normal`, `in progress`, `doing` | Standard workflow |
| 25, 1-39 | Low Priority | `low`, `later`, `someday` | Lower priority items |
| 0 | Wishlist | `wishlist` | Ideas and future enhancements |

## 🔒 Security

- Full CSP (Content Security Policy) compliance
- CSRF protection for all AJAX requests
- Proper permission validation
- No eval() or unsafe JavaScript execution

## 🧪 Testing

Before deploying to production:

1. Test in a development environment
2. Verify all priority mappings work correctly
3. Check browser console for any JavaScript errors
4. Ensure proper permissions are configured

## 🤝 Contributing

We welcome contributions! Please:

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests if applicable
5. Submit a pull request

## 📄 License

This project is licensed under the Apache License 2.0 - see the [LICENSE](LICENSE) file for details.

## 🆘 Support

- **Documentation**: Check `docs/UPDATE_COLUMNS.md` for detailed setup instructions
- **Issues**: Please use the GitHub issue tracker for bug reports and feature requests
- **Discussions**: Use GitHub Discussions for questions and community support

## 🎉 Acknowledgments

- Built for the [Phorge](https://phorge.it/) project
- Inspired by the need for efficient project management workflows
- Thanks to the Phorge community for testing and feedback

---

**⚠️ Note**: Always test in a development environment before deploying to production. 