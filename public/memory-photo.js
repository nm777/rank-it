const photoPicker = document.querySelector('[data-photo-picker]');
const photoInput = photoPicker.querySelector('input[type="file"]');
const openPhoto = photoPicker.querySelector('[data-photo-open]');
const selectedPhoto = photoPicker.querySelector('[data-selected-photo]');
const photoNames = photoPicker.querySelector('[data-photo-names]');
const clearPhoto = photoPicker.querySelector('[data-photo-clear]');
const memorySubmit = photoPicker.querySelector('[data-memory-submit]');

function updateSelectedPhoto() {
    const files = Array.from(photoInput.files);
    const items = files.map(file => {
        const item = document.createElement('li');
        item.textContent = file.name;
        return item;
    });
    photoNames.replaceChildren(...items);
    selectedPhoto.hidden = files.length === 0;
    if (memorySubmit) memorySubmit.textContent = files.length ? 'Save memory' : 'Submit';
}

photoInput.hidden = true;
openPhoto.hidden = false;
openPhoto.addEventListener('click', () => photoInput.click());
photoInput.addEventListener('invalid', event => {
    event.preventDefault();
    openPhoto.focus();
    photoInput.click();
});
photoInput.addEventListener('change', updateSelectedPhoto);
clearPhoto.addEventListener('click', () => {
    photoInput.value = '';
    updateSelectedPhoto();
    openPhoto.focus();
});
window.addEventListener('pageshow', updateSelectedPhoto);
updateSelectedPhoto();
