# Security Information

## 📋 Sanitization Notice

This public repository has been sanitized to remove confidential information before publication. The following sensitive data has been replaced with templates and placeholders:

### 🔒 Removed Sensitive Information

#### Server Information
- **IP Addresses**: `192.168.124.25`, `192.168.124.28` → `${PHABRICATOR_SERVER}`
- **Usernames**: `root@` → `${PHABRICATOR_USER}@`
- **Server paths**: Hardcoded paths → Environment variables

#### Credentials
- **Passwords**: `orangepi` → Template placeholder
- **Password files**: `ssh_server/orangepizero3psw` → `ssh_server/server_password` (template)
- **SSH keys**: Any embedded keys → Template references

#### Deployment Scripts
- **Automation scripts**: Converted to templates requiring user configuration
- **Connection strings**: Parameterized with environment variables

## 🛡️ Security Best Practices

### Before Deployment

1. **Create Your Configuration**
   ```bash
   # Copy template files
   cp ssh_server/deployment_config.template ssh_server/deployment_config
   cp ssh_server/server_password.template ssh_server/server_password
   
   # Edit with your actual values
   nano ssh_server/deployment_config
   nano ssh_server/server_password
   ```

2. **Set Proper Permissions**
   ```bash
   # Restrict access to sensitive files
   chmod 600 ssh_server/server_password
   chmod 600 ssh_server/deployment_config
   chmod 700 ssh_server/
   ```

3. **Configure Environment Variables**
   ```bash
   export PHABRICATOR_SERVER="your.server.ip"
   export PHABRICATOR_USER="your_username"
   export PASSWORD_FILE="ssh_server/server_password"
   ```

### File Security

#### ✅ Safe to Commit
- Template files (`*.template`)
- Source code files
- Documentation
- Configuration examples with placeholders

#### ❌ Never Commit
- Actual passwords or credentials
- Real IP addresses or server names
- SSH private keys
- Production configuration files
- Files matching patterns in `.gitignore`

### Network Security

#### Recommended Setup
- Use SSH key authentication instead of passwords when possible
- Configure firewall rules to restrict access
- Use VPN or secure network for deployment
- Enable fail2ban or similar intrusion prevention

#### Example SSH Key Setup
```bash
# Generate SSH key pair
ssh-keygen -t ed25519 -f ~/.ssh/phabricator_deploy

# Copy public key to server
ssh-copy-id -i ~/.ssh/phabricator_deploy.pub user@your.server.ip

# Use key authentication in scripts
ssh -i ~/.ssh/phabricator_deploy user@your.server.ip
```

## 🔍 Verification Checklist

Before deploying or sharing your configuration:

- [ ] No real passwords in files
- [ ] No production IP addresses in documentation
- [ ] SSH keys are not embedded in scripts
- [ ] Environment variables are used for sensitive data
- [ ] Proper file permissions are set (600 for secrets)
- [ ] `.gitignore` excludes all sensitive files
- [ ] All template files have been customized
- [ ] Test deployment in development environment first

## 🚨 Incident Response

If sensitive information is accidentally committed:

1. **Immediately change all exposed credentials**
2. **Remove sensitive data from Git history**:
   ```bash
   git filter-branch --force --index-filter \
   'git rm --cached --ignore-unmatch path/to/sensitive/file' \
   --prune-empty --tag-name-filter cat -- --all
   ```
3. **Force push to overwrite history** (if safe to do so)
4. **Notify all team members to reclone the repository**

## 📞 Reporting Security Issues

If you discover a security vulnerability:

1. **Do not create a public issue**
2. **Contact the maintainers directly**
3. **Provide detailed information about the vulnerability**
4. **Allow time for the issue to be addressed before public disclosure**

## 🔧 Template Configuration

### Required Files to Create

1. **`ssh_server/deployment_config`** - Main configuration
2. **`ssh_server/server_password`** - Server password (if not using SSH keys)
3. **Custom deployment scripts** - If you need environment-specific modifications

### Environment Variables

The following environment variables should be configured:

```bash
PHABRICATOR_SERVER="your.server.ip"
PHABRICATOR_USER="your_username"
PASSWORD_FILE="ssh_server/server_password"
DOCKER_CONTAINER_NAME="phabricator"
PHABRICATOR_EXTENSION_PATH="/srv/phabricator/src/extensions/PhorgeAutoMove"
```

## 📚 Additional Resources

- [Phabricator Security Guide](https://secure.phabricator.com/book/phabricator/article/configuring/)
- [SSH Security Best Practices](https://infosec.mozilla.org/guidelines/openssh)
- [Git Security Guidelines](https://git-scm.com/book/en/v2/Git-Tools-Signing-Your-Work)

---

**Remember**: Security is an ongoing process. Regularly review and update your configurations, and stay informed about security best practices for your deployment environment. 