<script setup>
import { ref, onMounted, computed } from 'vue'
import { niceFileSize, timeUntilExpiration, getApiUrl, niceFileType, niceFileName } from '../utils'
import { FileIcon, HeartCrack, TrendingDown, FileX, FolderOpen, KeyRound, Download, ChevronDown } from 'lucide-vue-next'
import { getShare, logout } from '../api'
import { domError } from '../domData'
import { useToast } from 'vue-toastification'
import { useTranslate } from '@tolgee/vue'
import DirectoryItem from './directory-item.vue'

const { t } = useTranslate()

const apiUrl = getApiUrl()
const toast = useToast()
const share = ref(null)
const showFiles = ref(false)
const shareExpired = ref(false)
const downloadLimitReached = ref(false)
const shareNotFound = ref(false)

//define props
const props = defineProps({
  downloadShareCode: {
    type: String,
    required: true
  }
})

onMounted(() => {
  fetchShare()
  // Full-bleed on iOS: with viewport-fit=cover the photo extends under the
  // status bar and the liquid-glass toolbar. Tint both dark so they blend
  // with the photo instead of the default light chrome.
  let themeColor = document.querySelector('meta[name="theme-color"]')
  if (!themeColor) {
    themeColor = document.createElement('meta')
    themeColor.setAttribute('name', 'theme-color')
    document.head.appendChild(themeColor)
  }
  themeColor.setAttribute('content', '#000000')
  setTimeout(() => {
    const urlParams = new URLSearchParams(window.location.search)
    const errorMessage = urlParams.get('error')

    if (errorMessage) {
      if (errorMessage == 'password_required') {
        toast.error(t.value('share.download.password_required'))
      } else if (errorMessage == 'invalid_password') {
        toast.error(t.value('share.download.invalid_password'))
      }
    }
  }, 100)
})

const fetchShare = async () => {
  try {
    share.value = await getShare(props.downloadShareCode)
    document.title = share.value.name
  } catch (error) {
    console.log('error', error)
    if (error.message == 'Download limit reached') {
      downloadLimitReached.value = true
    } else if (error.message == 'Share expired') {
      shareExpired.value = true
    } else if (error.message == 'Share not found') {
      shareNotFound.value = true
    }
  }
}

const goToLogin = async () => {
  // Remember where the user wanted to go so we can return after login
  sessionStorage.setItem('post_login_redirect', window.location.pathname)
  // End any active (guest) session so the login form is shown
  await logout()
  window.location.href = '/'
}

const downloadFiles = () => {
  const downloadUrl = `${apiUrl}/api/shares/${props.downloadShareCode}/download`
  window.location.href = downloadUrl
}

const splitFullName = (fullName) => {
  if (!fullName) {
    return 'creator'
  }
  const nameParts = fullName.split(' ')
  return nameParts[0]
}

const password = ref('')
const error = ref(null)

const downloadPasswordProtectedFiles = () => {
  //is the password filled in?
  if (!password.value) {
    toast.error(t.value('share.download.password_required'))
    error.value = t.value('share.download.password_required_short')
    return
  }

  //create a form and submit it
  const form = document.createElement('form')
  form.action = `${apiUrl}/api/shares/${props.downloadShareCode}/download`
  form.method = 'POST'

  //add the password input
  const passwordInput = document.createElement('input')
  passwordInput.type = 'password'
  passwordInput.name = 'password'
  passwordInput.value = password.value
  form.appendChild(passwordInput)

  // Add the form to the document body - THIS LINE IS CRUCIAL
  document.body.appendChild(form)

  // Submit the form
  form.submit()
  setTimeout(() => document.body.removeChild(form), 0)
}

const expiryText = computed(() => {
  if (!share.value) return ''
  const { days, hours, minutes } = timeUntilExpiration(share.value.expires_at)
  return t.value('share.expires.in', { days, hours, minutes })
})

const filesByDirectory = computed(() => {
  const files = share?.value?.files
  const structure = {}

  if (!files) {
    return {}
  }

  files.forEach((file) => {
    const path = file.full_path || ''
    const dirs = path ? path.split('/') : ['']

    // Create nested structure
    let current = structure
    for (const dir of dirs) {
      if (dir) {
        if (!current[dir]) {
          current[dir] = { files: [], directories: {} }
        }
        current = current[dir].directories
      }
    }

    // Add file to its directory
    if (path) {
      const parentDir = dirs.reduce((acc, dir, index) => {
        if (index < dirs.length - 1 && dir) {
          return acc[dir].directories
        }
        return acc
      }, structure)

      const lastDir = dirs[dirs.length - 1]
      if (lastDir) {
        parentDir[lastDir].files.push(file)
      }
    } else {
      // Root files
      if (!structure.files) {
        structure.files = []
      }
      structure.files.push(file)
    }
  })

  return structure
})
</script>

<template>
  <div class="download-hero">
    <div class="download-scrim"></div>
    <template v-if="share">
      <div class="download-hero-content">
        <p class="download-kicker">{{ $t('share.download.shared_with_you', 'Shared with you') }}</p>
        <h1 class="share-name">{{ share.name }}</h1>
        <p class="share-meta">
          <span>{{ niceFileSize(share.size) }}</span>
          <span class="meta-dot">&middot;</span>
          <span>{{ $t('share.contains.count', 'Contains: {value} files', { value: share.file_count }) }}</span>
          <span class="meta-dot">&middot;</span>
          <span>{{ expiryText }}</span>
        </p>

        <div class="download-actions" v-if="!share.password_protected">
          <button class="download-button-hero" @click="downloadFiles">
            <Download />
            {{ $t('download.files', 'Download {value} files', { value: share.file_count }) }}
          </button>
        </div>
        <div class="download-actions" v-else>
          <div class="password-row">
            <input
              type="password"
              v-model="password"
              :placeholder="$t('settings.share.password')"
              :class="{ error: error }"
              @keyup.enter="downloadPasswordProtectedFiles"
            />
            <button class="download-button-hero" @click="downloadPasswordProtectedFiles">
              <Download />
              {{ $t('download.files', 'Download {value} files', { value: share.file_count }) }}
            </button>
          </div>
          <div class="error-message" v-if="error">
            {{ error }}
          </div>
        </div>

        <button class="files-toggle" @click="showFiles = !showFiles">
          {{
            showFiles
              ? $t('share.download.hide_files', 'Hide files')
              : $t('share.download.view_files', 'View files')
          }}
          <ChevronDown :class="{ open: showFiles }" />
        </button>
        <div class="share-files-list" v-show="showFiles">
          <directory-item
            :structure="filesByDirectory"
            :is-root="true"
            :read-only="true"
            :share-code="downloadShareCode"
          />
        </div>

        <div class="share-message" v-if="share.description">
          <h6>{{ $t('message.from', { name: splitFullName(share.user.name) }) }}</h6>
          <p class="message">
            {{ share.description }}
          </p>
        </div>
      </div>
    </template>
    <template v-else>
      <div class="share-status">
        <template v-if="shareExpired">
          <h1>
            <HeartCrack />
            {{ $t('share.expired') }}
          </h1>
          <p>{{ $t('share.expired.message') }}</p>
        </template>
        <template v-else-if="downloadLimitReached">
          <h1>
            <TrendingDown />
            {{ $t('share.download_limit_reached') }}
          </h1>
          <p>
            {{ $t('share.download_limit_reached.message') }}
          </p>
        </template>
        <template v-else-if="shareNotFound">
          <div class="my-5">
            <FileX />
          </div>
          <h1>
            {{ $t('share.not_found') }}
          </h1>
          <div class="download-button-container">
            <button class="download-button-hero" @click="goToLogin">
              <KeyRound />
              {{ $t('share.not_found.login') }}
            </button>
          </div>
        </template>
        <h1 v-else>{{ $t('share.data_loading') }}</h1>
      </div>
    </template>
  </div>
</template>
