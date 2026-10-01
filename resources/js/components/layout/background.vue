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

// Slideshow preloading: the swap only happens once the target image is
// decoded — never a black frame. A read-ahead preloads the next background
// during the current cycle, and an eager warmer decodes everything else in
// the background (staggered) so swaps stay on cadence like the original
// crossfade. If the target isn't ready when the timer fires, the current
// image simply stays.
let nextIndex = null
let nextReady = false
const decodedCache = {} // index -> true once the optimized file is decoded
const failedCache = {} // index -> true if the file failed to load
const decodeQueue = []
const DECODE_CONCURRENCY = 4
let activeDecodes = 0

const pumpDecodes = () => {
  while (activeDecodes < DECODE_CONCURRENCY && decodeQueue.length) {
    const index = decodeQueue.shift()
    if (decodedCache[index] || failedCache[index]) continue
    activeDecodes++
    // Warm the server + HTTP cache with a plain fetch: the bytes are
    // downloaded without decoding the bitmap. Decoding 70 full-screen
    // photos up front via `new Image()` exhausted Mobile Safari's tab
    // memory and crash-looped the page; the 0.5s crossfade covers the
    // ~50ms local decode at swap time.
    fetch(`/api/backgrounds/${backgroundFiles.value[index]}/optimized`)
      .then((res) => {
        if (!res.ok) throw new Error('warm failed')
        return res.arrayBuffer() // drain the body so bytes land in cache
      })
      .then(() => markWarmed(index, true))
      .catch(() => markWarmed(index, false))
  }
}

const markWarmed = (index, ok) => {
  activeDecodes--
  if (ok) {
    decodedCache[index] = true
    if (nextIndex === index) nextReady = true
  } else {
    failedCache[index] = true
    if (nextIndex === index) nextReady = false
  }
  pumpDecodes()
}

const queueDecode = (index) => {
  if (decodedCache[index] || failedCache[index]) return
  if (!decodeQueue.includes(index)) decodeQueue.push(index)
  pumpDecodes()
}

// The single immediate-next background is fully decoded (one bitmap is
// harmless) so its swap is instant with zero black risk.
const decodeNext = (index) => {
  const img = new Image()
  img.onload = () => {
    decodedCache[index] = true
    if (nextIndex === index) nextReady = true
  }
  img.onerror = () => {
    failedCache[index] = true
    if (nextIndex === index) nextReady = false
  }
  img.src = `/api/backgrounds/${backgroundFiles.value[index]}/optimized`
}

const pickRandomIndex = (exclude) => {
  const n = backgroundFiles.value.length
  if (n === 0) return null
  if (n === 1) return 0
  for (let tries = 0; tries < n; tries++) {
    const idx = Math.floor(Math.random() * n)
    if (idx !== exclude && !failedCache[idx]) return idx
  }
  return null
}

const preloadNext = (index) => {
  nextIndex = index
  nextReady = !!decodedCache[index]
  const file = backgroundFiles.value[index]
  if (isVideo(file)) {
    // Videos render on demand when active; keep existing behavior.
    nextReady = true
    return
  }
  if (!nextReady) decodeNext(index)
}

const prepareNext = () => {
  const idx = pickRandomIndex(currentBackgroundIndex.value)
  if (idx == null) return
  preloadNext(idx)
}

// Decode every background up front (in the background) so the slideshow
// never waits on a cold server-side WebP encode mid-cycle. Skipped when the
// user has Data Saver on.
const warmAll = () => {
  if (navigator.connection && navigator.connection.saveData) return
  backgroundFiles.value.forEach((file, i) => {
    if (!isVideo(file) && i !== currentBackgroundIndex.value) queueDecode(i)
  })
}

const isActive = (index) => {
  return index === currentBackgroundIndex.value
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
        // transition is already covered, then warm the rest.
        prepareNext()
        warmAll()
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
  if (nextReady && nextIndex != null && nextIndex !== currentBackgroundIndex.value) {
    currentBackgroundIndex.value = nextIndex
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
        :style="{ backgroundImage: `url(/api/backgrounds/${file}/optimized)`, backgroundPosition: backgroundPosition(file) }"
      ></div>
    </template>
  </div>
</template>
