<script setup>
import { ref, onMounted } from 'vue'

import { getBackgroundImages } from '../../api'
import { domData } from '../../domData'

const VIDEO_EXTENSIONS = ['mp4', 'webm']

const slideshowSpeed = ref(30)
const useMyBackgrounds = ref(false)
const backgroundFiles = ref([])
const focalPoints = ref({})
const interval = ref(null)
const currentBackgroundIndex = ref(0)

const isVideo = (filename) => {
  const extension = filename.split('.').pop().toLowerCase()
  return VIDEO_EXTENSIONS.includes(extension)
}

// Slideshow preloading: the swap only happens once the next image is fully
// decoded — never a black frame. A read-ahead preloads exactly one image
// (the next background) during the current cycle. Deliberately no bulk
// loading of any kind: the template used to bind a background-image URL on
// every one of the ~76 slide divs, so Mobile Safari fetched and decoded all
// of them on cold load (~330MB of bitmaps) and jetsam-killed the tab —
// removing the JS warming in #53 wasn't enough on its own. Only the active
// slide, the slide fading out, and the preloading next slide ever carry an
// image URL; every other div renders empty. If the target isn't ready when
// the timer fires, the current image simply stays.
const preloadIndex = ref(null)
const fadingIndex = ref(null)
let fadeTimeout = null
let nextReady = false
let nextImg = null

const pickRandomIndex = (exclude) => {
  const n = backgroundFiles.value.length
  if (n === 0) return null
  if (n === 1) return 0
  for (let tries = 0; tries < n; tries++) {
    const idx = Math.floor(Math.random() * n)
    if (idx !== exclude) return idx
  }
  return null
}

const preloadNext = (index) => {
  preloadIndex.value = index
  nextReady = false
  const file = backgroundFiles.value[index]
  if (isVideo(file)) {
    // Videos render on demand when active; keep existing behavior.
    nextReady = true
    return
  }
  // Keep a reference so the image isn't GC'd before it finishes loading.
  nextImg = new Image()
  nextImg.onload = () => { if (preloadIndex.value === index) nextReady = true }
  nextImg.onerror = () => { if (preloadIndex.value === index) nextReady = false }
  nextImg.src = `/api/backgrounds/${file}/optimized`
}

const prepareNext = () => {
  const idx = pickRandomIndex(currentBackgroundIndex.value)
  if (idx == null) return
  preloadNext(idx)
}

const isActive = (index) => {
  return index === currentBackgroundIndex.value
}

// Only these slides get an image URL: the visible one, the one fading out
// (it must stay painted for the 0.5s crossfade), and the one being
// preloaded. All others render as empty divs so the browser never fetches
// or decodes their images.
const shouldLoadImage = (index) => {
  return (
    index === currentBackgroundIndex.value ||
    index === fadingIndex.value ||
    index === preloadIndex.value
  )
}

// Smart-crop: position the cover-crop on the detected subject instead of
// always centering, so portrait subjects aren't cut out on narrow viewports.
const backgroundPosition = (file) => {
  const focal = focalPoints.value[file]
  if (focal && focal.x != null && focal.y != null) {
    return `${focal.x}% ${focal.y}%`
  }
  return 'center'
}

onMounted(() => {
  slideshowSpeed.value = domData().background_slideshow_speed
  useMyBackgrounds.value = domData().use_my_backgrounds
  
  if (useMyBackgrounds.value) {
    //remove the interval if it exists
    if (interval.value) {
      clearInterval(interval.value)
    }
    interval.value = setInterval(changeBackground, slideshowSpeed.value * 1000)
    getBackgroundImages().then((data) => {
      // The slideshow renders the display variant (border-cropped copy when
      // black film borders were detected, otherwise the original). Focal
      // points are keyed by original filename, so remap them onto the
      // display names the template iterates over.
      const displayFor = (f) => (data.display_files && data.display_files[f]) || f
      const focalByDisplay = {}
      data.files.forEach((f) => {
        focalByDisplay[displayFor(f)] = (data.focal_points || {})[f]
      })
      backgroundFiles.value = data.files.map(displayFor)
      focalPoints.value = focalByDisplay
      //start on a random background instead of always showing the first file
      if (data.files.length > 0) {
        currentBackgroundIndex.value = Math.floor(Math.random() * data.files.length)
        // Begin preloading the next background right away so the first
        // transition is already covered.
        prepareNext()
      }
    })
  }
})

const changeBackground = () => {
  if (!useMyBackgrounds.value || backgroundFiles.value.length === 0) {
    return
  }
  // Only swap to the preloaded background once it's decoded. If it isn't
  // ready yet, keep the current image instead of flashing black.
  if (nextReady && preloadIndex.value != null && preloadIndex.value !== currentBackgroundIndex.value) {
    // Keep the outgoing slide's image painted while it fades out, then
    // release it so its bitmap is freed.
    if (fadeTimeout) clearTimeout(fadeTimeout)
    fadingIndex.value = currentBackgroundIndex.value
    currentBackgroundIndex.value = preloadIndex.value
    preloadIndex.value = null
    fadeTimeout = setTimeout(() => { fadingIndex.value = null }, 600)
  }
  // Start loading the background after next.
  prepareNext()
}
</script>
<template>
  <div class="backgrounds" v-if="!useMyBackgrounds">
    <div
      class="backgrounds-item active"
      :style="{
        backgroundImage: `url(/images/default-background.jpg)`
      }"
    ></div>
  </div>

  <div class="backgrounds" v-else>
    <template v-for="(file, index) in backgroundFiles" :key="file">
      <!-- Video backgrounds - only render when active -->
      <div 
        v-if="isVideo(file) && isActive(index)" 
        class="backgrounds-item backgrounds-item-video active"
      >
        <video
          autoplay
          loop
          muted
          playsinline
          :src="`/backgrounds/${file}`"
        ></video>
      </div>
      <!-- Image backgrounds - always in DOM, toggle active class -->
      <div
        v-else-if="!isVideo(file)"
        class="backgrounds-item"
        :class="{ active: isActive(index) }"
        :style="shouldLoadImage(index) ? { backgroundImage: `url(/api/backgrounds/${file}/optimized)`, backgroundPosition: backgroundPosition(file) } : {}"
      ></div>
    </template>
  </div>
</template>
