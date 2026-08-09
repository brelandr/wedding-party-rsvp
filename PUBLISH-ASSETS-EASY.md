# 🚀 Publish Assets - Easiest Method

## Step-by-Step Instructions

### 1. Open GitHub Actions
Go to: **https://github.com/brelandr/wedding-party-rsvp/actions**

### 2. Find the Workflow
In the left sidebar, click on: **"Manual Asset & Readme Update"**

### 3. Run the Workflow
- Click the **"Run workflow"** button (top right)
- Select your branch (usually `main` or `master`)
- Click the green **"Run workflow"** button

### 4. Wait for Completion
The workflow will:
- ✅ Check out your code
- ✅ Upload all assets from the `assets/` folder
- ✅ Update the `readme.txt` file
- ✅ Commit everything to WordPress.org SVN

### 5. Verify
After a few minutes, check:
- **SVN:** https://plugins.svn.wordpress.org/wedding-party-rsvp/assets/
- **Plugin Page:** https://wordpress.org/plugins/wedding-party-rsvp/

---

**That's it!** The workflow uses your stored GitHub Secrets, so no passwords needed! 🎉
