package app.jarvis.android.ui.components

import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.SegmentedButton
import androidx.compose.material3.SegmentedButtonDefaults
import androidx.compose.material3.SingleChoiceSegmentedButtonRow
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp

/** Material segmented buttons over a screen, to pick one filter; without the check of the selected one when the labels need its room. */
@Composable
fun <T> FilterRow(options: List<T>, selected: T, onSelect: (T) -> Unit, showsSelectionIcon: Boolean = true, label: @Composable (T) -> String) {
    SingleChoiceSegmentedButtonRow(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 4.dp)) {
        options.forEachIndexed { index, option ->
            SegmentedButton(
                selected = selected == option,
                onClick = { onSelect(option) },
                shape = SegmentedButtonDefaults.itemShape(index, options.size),
                icon = if (showsSelectionIcon) {
                    { SegmentedButtonDefaults.Icon(selected == option) }
                } else {
                    {}
                },
            ) {
                Text(label(option), maxLines = 1)
            }
        }
    }
}
